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
#   ops-collect.sh <output-path>
#
# Install (as root, crontab -e or /etc/cron.d), every 5 minutes:
#
#   */5 * * * * root /path/to/ops-collect.sh /var/www/clients/clientN/webN/web/.admin/tmp/ops-snapshot.json
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
set -euo pipefail

out="${1:?usage: ops-collect.sh <output-path>}"
out_dir="$(dirname -- "$out")"

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
f2b_banned=0
if command -v fail2ban-client >/dev/null 2>&1; then
    jail_line="$(fail2ban-client status 2>/dev/null | awk -F: '/Jail list:/ { print $2 }' || true)"
    jail_line="${jail_line//,/ }"
    parts=()
    for jail in $jail_line; do
        safe_jail="$(printf '%s' "$jail" | tr -cd 'A-Za-z0-9@._-')"
        [ -n "$safe_jail" ] || continue
        banned="$(fail2ban-client status "$safe_jail" 2>/dev/null | awk -F: '/Currently banned:/ { gsub(/ /,"",$2); print $2 }' || true)"
        case "$banned" in
            ''|*[!0-9]*) banned=0 ;;
        esac
        parts+=("\"${safe_jail}\":${banned}")
        f2b_banned=$((f2b_banned + banned))
    done
    if [ "${#parts[@]}" -gt 0 ]; then
        f2b_json="{$(IFS=,; echo "${parts[*]}")}"
    fi
fi

# ---------------------------------------------------------------------
# auth — last 24h sshd "Failed password"/"Invalid user" vs "Accepted",
# from journalctl when available, else /var/log/auth.log. Best-effort: 0
# when neither source is readable.
# ---------------------------------------------------------------------
ssh_failed=0
ssh_accepted=0
window_h=24
if command -v journalctl >/dev/null 2>&1; then
    lines="$(journalctl -u ssh -u sshd --since "24 hours ago" -o cat 2>/dev/null || true)"
    ssh_failed="$(printf '%s\n' "$lines" | grep -cE 'Failed password|Invalid user' || true)"
    ssh_accepted="$(printf '%s\n' "$lines" | grep -c 'Accepted' || true)"
elif [ -r /var/log/auth.log ]; then
    ssh_failed="$(grep -cE 'Failed password|Invalid user' /var/log/auth.log 2>/dev/null || true)"
    ssh_accepted="$(grep -c 'Accepted' /var/log/auth.log 2>/dev/null || true)"
fi
case "$ssh_failed" in ''|*[!0-9]*) ssh_failed=0 ;; esac
case "$ssh_accepted" in ''|*[!0-9]*) ssh_accepted=0 ;; esac

at="$(date +%s)"

# ---------------------------------------------------------------------
# Write atomically: a temp file in the SAME directory as the output (so the
# final mv is a same-filesystem rename, not a copy), mode 0644, then mv into
# place. A reader (SnapshotFileSource) never sees a partially written file.
# ---------------------------------------------------------------------
tmp="$(mktemp "${out_dir}/.ops-snapshot.XXXXXX")"
trap 'rm -f "$tmp"' EXIT

printf '{"load1":%s,"mem_pct":%s,"disk_pct":%s,"services":%s,"f2b_banned":%s,"f2b":%s,"auth":{"ssh_failed":%s,"ssh_accepted":%s,"window_h":%s},"at":%s}\n' \
    "$load1" "$mem_pct" "$disk_pct" "$services_json" "$f2b_banned" "$f2b_json" \
    "$ssh_failed" "$ssh_accepted" "$window_h" "$at" > "$tmp"

chmod 0644 "$tmp"
mv -f "$tmp" "$out"
trap - EXIT
