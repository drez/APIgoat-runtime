#!/usr/bin/env bash
#
# ops-collect.sh — root-cron collector for the Security & Performance
# dashboards' server health source ("snapshot" server_source, Task 0
# ruling): a production host running ISPConfig has the PHP app jailed as an
# unprivileged web user with no MySQL/root access, but the server owner has
# root. This script runs as root, reads a handful of host-health probes,
# and writes them as one JSON file INSIDE the site's own open_basedir so the
# jailed app can read it — that file is all the app ever touches; it never
# talks to systemd/fail2ban/journalctl itself.
#
# Usage:
#   ops-collect.sh <absolute-output-path>
#
# Install (as root) — NEVER run this script in place from vendor/ or from
# anywhere else inside the web tree: the web user can write there, so a
# root cron pointed at that copy would run whatever the web user put in it,
# i.e. hand it root. Install a root-owned copy outside the web tree instead
# and point the crontab at THAT copy:
#
#   install -o root -g root -m 0755 /path/to/.admin/vendor/apigoat/runtime/bin/ops-collect.sh /usr/local/sbin/ops-collect.sh
#
# then (crontab -e as root, or a root-owned file in /etc/cron.d), every 5
# minutes:
#
#   */5 * * * * root /usr/local/sbin/ops-collect.sh /var/www/clients/clientN/webN/web/.admin/tmp/ops-snapshot.json
#
# Re-run the `install` line after a runtime update to pick up a new version.
#
# The output path must be the CANONICAL path, with no symlinked component
# anywhere in it (`realpath -e <dir>` must print the directory unchanged).
# On ISPConfig that means /var/www/clients/clientN/webN/..., NOT the
# /var/www/<domain>/... convenience symlink — that path is refused.
#
# The app reads this file hourly (the opsServerSnap cron job, P/.admin/config/cron.php)
# via ApiGoat\Ops\Server\SnapshotFileSource, so 5-minute freshness is a
# comfortable margin, not a requirement — the reader shows the snapshot's
# age rather than discarding a slightly stale one.
#
# Every probe below tolerates its tool being absent or unusable (no
# systemctl, no fail2ban-client, no journalctl, unreadable /proc/*, ...): it
# substitutes a safe default (0 / empty object) instead of aborting, so the
# script still produces a valid — just less complete — snapshot on a
# minimal box. Written with `set -euo pipefail`; every place a probe COULD
# legitimately fail (grep with no match, a missing binary, ...) is guarded
# with an `if`/`|| true` so that failure never trips the script itself.
#
# Fix round 1 (R16): running as root but writing into a directory the
# jailed web user controls means that user could swap a path component for
# a symlink and trick this script into writing a root-owned file somewhere
# else entirely (or overwriting an arbitrary file through a symlinked
# output path). Before doing anything else this script refuses to run
# unless BOTH the output directory and the output path itself are exactly
# what they claim to be — no symlink anywhere in the directory, and the
# output path (if it exists at all) is a plain regular file, never a
# symlink or a directory.
set -euo pipefail

out="${1:?usage: ops-collect.sh <absolute-output-path>}"

# R18 (controller ruling, fix round 2): the symlink check below compares
# `realpath -e`'s (always absolute) result against the literal path we were
# given -- a relative path (e.g. "tmp/out.json") can never equal that
# absolute result even with no symlink anywhere, so it was being refused
# with the wrong, misleading message ("a symlink ... is involved"). Require
# an absolute path outright, before that check ever runs, with its own
# distinct message and exit code.
case "$out" in
    /*) ;;
    *)
        printf 'ops-collect.sh: output path must be absolute: %s\n' "$out" >&2
        exit 2
        ;;
esac

out_dir="$(dirname -- "$out")"

# The output directory must exist, contain no symlink in its resolved path,
# and not itself be a symlink. `realpath -e` both requires existence and
# fully resolves every component; a result that differs from the literal
# $out_dir we were given means a symlink (or a `..`/relative component) sat
# somewhere in the path — refuse rather than silently write through it.
# Callers are documented (see the install line above) to pass an absolute,
# already-canonical directory, so a legitimate call never differs here.
if ! real_dir="$(realpath -e -- "$out_dir" 2>/dev/null)"; then
    printf 'ops-collect.sh: output directory does not exist or cannot be resolved: %s\n' "$out_dir" >&2
    exit 1
fi
if [ "$real_dir" != "$out_dir" ] || [ -L "$out_dir" ]; then
    printf 'ops-collect.sh: refusing to write into %s -- a symlink (or non-canonical path) is involved\n' "$out_dir" >&2
    exit 1
fi

# The output path itself, if it already exists, must be a plain regular
# file -- never a symlink (which `mv -f` would otherwise happily replace
# the TARGET of, not the link itself is fine, but we still refuse outright
# for predictability) and never a directory or other special file.
if [ -e "$out" ] && { [ -L "$out" ] || [ ! -f "$out" ]; }; then
    printf 'ops-collect.sh: refusing to write to %s -- it exists and is not a regular file\n' "$out" >&2
    exit 1
fi

# ---------------------------------------------------------------------
# load1 — 1-minute load average, from /proc/loadavg.
# ---------------------------------------------------------------------
load1="0.00"
if [ -r /proc/loadavg ]; then
    load1="$(awk '{printf "%.2f", $1}' /proc/loadavg 2>/dev/null || true)"
    [ -n "$load1" ] || load1="0.00"
fi

# ---------------------------------------------------------------------
# mem_pct — percent of memory in use, from /proc/meminfo
# (MemTotal/MemAvailable, the same fields `free` uses on modern kernels).
# ---------------------------------------------------------------------
mem_pct="0.00"
if [ -r /proc/meminfo ]; then
    mem_pct="$(awk '
        /^MemTotal:/     { total = $2 }
        /^MemAvailable:/ { avail = $2 }
        END {
            if (total > 0 && avail != "") {
                printf "%.2f", (total - avail) / total * 100
            } else {
                print "0.00"
            }
        }
    ' /proc/meminfo 2>/dev/null || true)"
    [ -n "$mem_pct" ] || mem_pct="0.00"
fi

# ---------------------------------------------------------------------
# disk_pct — percent of `/` in use, from `df -P /`.
# ---------------------------------------------------------------------
disk_pct="0.00"
if command -v df >/dev/null 2>&1; then
    raw_pct="$(df -P / 2>/dev/null | awk 'NR==2 { gsub("%","",$5); print $5 }' || true)"
    case "$raw_pct" in
        ''|*[!0-9]*) disk_pct="0.00" ;;
        *) disk_pct="$(awk -v p="$raw_pct" 'BEGIN { printf "%.2f", p }')" ;;
    esac
fi

# ---------------------------------------------------------------------
# services — systemctl is-active for nginx/apache2/mariadb/mysql (whichever
# unit files exist) plus every php*-fpm unit systemd currently knows about.
# ---------------------------------------------------------------------
service_names=()
if command -v systemctl >/dev/null 2>&1; then
    for svc in nginx apache2 mariadb mysql; do
        if systemctl list-unit-files "${svc}.service" 2>/dev/null | grep -q "^${svc}\.service"; then
            service_names+=("$svc")
        fi
    done
    while IFS= read -r unit; do
        if [ -n "$unit" ]; then
            service_names+=("${unit%.service}")
        fi
    done < <(systemctl list-units --type=service --all 'php*-fpm*' --no-legend 2>/dev/null | awk '{print $1}' || true)
fi

services_json="{}"
if [ "${#service_names[@]}" -gt 0 ]; then
    parts=()
    for svc in "${service_names[@]}"; do
        # Restrict to a safe identifier charset before it ever reaches JSON —
        # these names ultimately come from systemd unit listings, not
        # attacker input, but building JSON with printf (no jq) means we
        # never quote-escape, so the charset restriction IS the escaping.
        safe_svc="$(printf '%s' "$svc" | tr -cd 'A-Za-z0-9@._-')"
        [ -n "$safe_svc" ] || continue
        state="inactive"
        if command -v systemctl >/dev/null 2>&1; then
            state="$(systemctl is-active "$safe_svc" 2>/dev/null || true)"
        fi
        if [ "$state" = "active" ]; then
            parts+=("\"${safe_svc}\":true")
        else
            parts+=("\"${safe_svc}\":false")
        fi
    done
    if [ "${#parts[@]}" -gt 0 ]; then
        services_json="{$(IFS=,; echo "${parts[*]}")}"
    fi
fi

# ---------------------------------------------------------------------
# f2b / f2b_banned — fail2ban-client status, then per-jail "Currently
# banned" count. Empty object (and 0) when fail2ban-client is absent.
# ---------------------------------------------------------------------
f2b_json="{}"
f2b_logs_json="{}"
f2b_banned=0
if command -v fail2ban-client >/dev/null 2>&1; then
    jail_line="$(fail2ban-client status 2>/dev/null | awk -F: '/Jail list:/ { print $2 }' || true)"
    jail_line="${jail_line//,/ }"
    parts=()
    for jail in $jail_line; do
        safe_jail="$(printf '%s' "$jail" | tr -cd 'A-Za-z0-9@._-')"
        [ -n "$safe_jail" ] || continue
        banned="$(fail2ban-client status "$safe_jail" 2>/dev/null | awk -F: '/Currently banned:/ { gsub(/[ \t]/,"",$2); print $2 }' || true)"
        case "$banned" in
            ''|*[!0-9]*) banned=0 ;;
        esac
        parts+=("\"${safe_jail}\":${banned}")
        f2b_banned=$((f2b_banned + banned))
    done
    if [ "${#parts[@]}" -gt 0 ]; then
        f2b_json="{$(IFS=,; echo "${parts[*]}")}"
    fi
    # f2b_logs — the log files the `gc fail2ban` scanner jails watch, so a
    # deploy (which runs as the jailed web user and cannot ask fail2ban) can
    # tell whether THIS site is covered. Only those two jails; paths keep
    # [A-Za-z0-9/._*-] and nothing else (they land in JSON unescaped).
    lparts=()
    for gj in gc-probe gc-probe-all; do
        [ -n "$(printf '%s\n' $jail_line | grep -x "$gj" || true)" ] || continue
        logs=()
        while IFS= read -r lp; do
            lp="$(printf '%s' "$lp" | sed -E 's/^[|`]- +//' | tr -cd 'A-Za-z0-9/._*-')"
            case "$lp" in
                /*) logs+=("\"${lp}\"") ;;
            esac
        done < <(fail2ban-client get "$gj" logpath 2>/dev/null || true)
        lparts+=("\"${gj}\":[$(IFS=,; echo "${logs[*]}")]")
    done
    if [ "${#lparts[@]}" -gt 0 ]; then
        f2b_logs_json="{$(IFS=,; echo "${lparts[*]}")}"
    fi
fi

# ---------------------------------------------------------------------
# auth — last 24h sshd "Failed password" vs "Accepted", from journalctl
# when available, else a syslog auth file (/var/log/auth.log, or the file
# named by OPS_COLLECT_AUTH_LOG, e.g. /var/log/secure on RHEL). Best-effort:
# 0 when no source is readable.
#
# Counted: "Failed password" lines only. One attempt for an unknown user logs
# BOTH "Invalid user x" and "Failed password for invalid user x", so counting
# both double-counted it; the "Failed password" line alone covers valid and
# invalid users. Not counted: probes that never try a password at all
# (pre-auth disconnects, key-only failures).
#
# The auth-file fallback keeps only lines from the last 24h by their syslog
# timestamp — RFC 3339 ("2026-09-25T01:02:03...") or traditional
# ("Sep 25 01:02:03", year-less; a Dec->Jan window is handled). Timestamps
# are compared as local time, ignoring any UTC offset in the line; when GNU
# `date -d` is unavailable the whole file is counted instead.
# ---------------------------------------------------------------------
ssh_failed=0
ssh_accepted=0
window_h=24

count_ssh() {
    awk '/Failed password/ { f++ } /Accepted / { a++ } END { printf "%d %d\n", f, a }'
}

auth_log="${OPS_COLLECT_AUTH_LOG:-/var/log/auth.log}"
counts=""
if [ -z "${OPS_COLLECT_AUTH_LOG:-}" ] && command -v journalctl >/dev/null 2>&1; then
    counts="$(journalctl -u ssh -u sshd --since "24 hours ago" -o cat 2>/dev/null | count_ssh || true)"
elif [ -r "$auth_log" ]; then
    if cut_iso="$(date -d '24 hours ago' '+%Y-%m-%dT%H:%M:%S' 2>/dev/null)" \
        && cut_bsd="$(date -d '24 hours ago' '+%m %d %H:%M:%S' 2>/dev/null)"; then
        now_iso="$(date '+%Y-%m-%dT%H:%M:%S')"
        now_bsd="$(date '+%m %d %H:%M:%S')"
        counts="$(awk -v ci="$cut_iso" -v ni="$now_iso" -v cb="$cut_bsd" -v nb="$now_bsd" '
            BEGIN {
                split("Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec", m, " ")
                for (i = 1; i <= 12; i++) mon[m[i]] = sprintf("%02d", i)
            }
            !/sshd/ { next }
            $1 ~ /^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T/ {
                k = substr($1, 1, 19)
                if (k >= ci && k <= ni) print
                next
            }
            ($1 in mon) {
                k = sprintf("%s %02d %s", mon[$1], $2, $3)
                if (cb <= nb) { if (k >= cb && k <= nb) print }
                else if (k >= cb || k <= nb) print
            }
        ' "$auth_log" 2>/dev/null | count_ssh || true)"
    else
        counts="$(grep 'sshd' "$auth_log" 2>/dev/null | count_ssh || true)"
    fi
fi
if [ -n "$counts" ]; then
    ssh_failed="${counts%% *}"
    ssh_accepted="${counts##* }"
fi
case "$ssh_failed" in ''|*[!0-9]*) ssh_failed=0 ;; esac
case "$ssh_accepted" in ''|*[!0-9]*) ssh_accepted=0 ;; esac

at="$(date +%s)"

# ---------------------------------------------------------------------
# Write atomically: a temp file in the SAME directory as the output (so the
# final mv is a same-filesystem rename, not a copy), mode 0644, then mv into
# place. A reader (SnapshotFileSource) never sees a partially written file.
#
# TOCTOU (final review M1): the pre-flight symlink check above runs well
# before this write, and the directory's owner (the web user) could swap a
# component for a symlink in between. So when running as root into a
# directory owned by someone else, the write itself runs AS THAT OWNER
# (`runuser -u <owner>`): whatever a symlink points it at, it can only write
# where the web user could already write. When running as root without
# `runuser`, or into a root-owned directory, or as a non-root user, root
# privileges are not lent to anyone and the write stays in-process, after
# re-checking the directory right before it. Residual risk: only on a root
# run WITHOUT runuser into a non-root-owned directory, a symlink swapped in
# the instant between that re-check and mktemp/mv can still redirect this
# root write — install util-linux (runuser) to close it.
# ---------------------------------------------------------------------
payload="$(printf '{"load1":%s,"mem_pct":%s,"disk_pct":%s,"services":%s,"f2b_banned":%s,"f2b":%s,"f2b_logs":%s,"auth":{"ssh_failed":%s,"ssh_accepted":%s,"window_h":%s},"at":%s}' \
    "$load1" "$mem_pct" "$disk_pct" "$services_json" "$f2b_banned" "$f2b_json" "$f2b_logs_json" \
    "$ssh_failed" "$ssh_accepted" "$window_h" "$at")"

# The same steps in both branches; $1 = directory, $2 = output path, the
# payload on stdin.
write_snippet='umask 022
tmp="$(mktemp "$1/.ops-snapshot.XXXXXX")" || exit 1
if cat > "$tmp" && chmod 0644 "$tmp" && mv -f "$tmp" "$2"; then exit 0; fi
rm -f "$tmp"; exit 1'

dir_owner_uid="$(stat -c %u -- "$out_dir" 2>/dev/null || echo 0)"
dir_owner="$(stat -c %U -- "$out_dir" 2>/dev/null || echo UNKNOWN)"

if [ "$(id -u)" = "0" ] && [ "$dir_owner_uid" != "0" ] && [ "$dir_owner" != "UNKNOWN" ] \
    && command -v runuser >/dev/null 2>&1; then
    printf '%s\n' "$payload" | runuser -u "$dir_owner" -- sh -c "$write_snippet" sh "$out_dir" "$out"
else
    # Re-check right before the write (see above).
    if [ "$(realpath -e -- "$out_dir" 2>/dev/null || true)" != "$out_dir" ] || [ -L "$out_dir" ] \
        || { [ -e "$out" ] && { [ -L "$out" ] || [ ! -f "$out" ]; }; }; then
        printf 'ops-collect.sh: refusing to write to %s -- the path changed during collection\n' "$out" >&2
        exit 1
    fi
    printf '%s\n' "$payload" | sh -c "$write_snippet" sh "$out_dir" "$out"
fi
