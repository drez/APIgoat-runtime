<?php

namespace ApiGoat\Mail\Connector;

use ApiGoat\Mail\BackfillResult;
use ApiGoat\Mail\BaseConnector;
use ApiGoat\Mail\FetchResult;
use ApiGoat\Mail\FolderLister;
use ApiGoat\Mail\FolderRole;
use ApiGoat\Mail\FolderListing;
use ApiGoat\Mail\FolderWriter;
use ApiGoat\Mail\HeaderRecord;
use ApiGoat\Mail\Imap\ImapTransport;
use ApiGoat\Mail\Imap\WebklexTransport;
use ApiGoat\Mail\MailBody;
use ApiGoat\Mail\MailboxState;
use ApiGoat\Mail\MailConnector;
use ApiGoat\Mail\MessageState;
use ApiGoat\Mail\MimeBodyParser;
use ApiGoat\Mail\StateWriter;
use ApiGoat\Sync\Exceptions\TransientError;

/**
 * IMAP over {@see ImapTransport} (default {@see WebklexTransport}).
 *
 * Provider id = "<uid>:<folder>" — a UID is only unique within a folder and
 * a UIDVALIDITY generation, and move() reassigns it, so the folder travels
 * with the id. The configured folder is the default when an id has none.
 *
 * Cursor rules (MailboxState: uidvalidity + uidnext):
 *   - no cursor                     → cold start, reason 'initial'
 *   - server UIDVALIDITY ≠ cursor's → cold start, reason 'uidvalidity_changed' (loud: coldStart=true)
 *   - otherwise                     → UID >= cursor.uidnext, lowest $max first
 * Cold starts are bounded to the last $coldStartDays by INTERNALDATE and
 * take the NEWEST $max of that window; the cursor then covers exactly what
 * was returned (uidnext = last returned uid + 1, or the server's UIDNEXT
 * once the window is drained), so the next call is a plain increment.
 */
class ImapConnector extends BaseConnector implements FolderLister, FolderWriter, StateWriter
{
    private ImapTransport $imap;
    private string $folder;
    private int $coldStartDays;
    private ?string $trashFolder;
    private bool $connected = false;
    /** @var array{label_server:bool, all:?string, archive:?string}|null {@see FolderRole::imapLayout()}, read once per connector */
    private ?array $layout = null;

    /**
     * @param array{host:string, port?:int, encryption?:string, username:string, password:string,
     *               folder?:string, validate_cert?:bool, authentication?:string, timeout?:int,
     *               cold_start_days?:int, trash_folder?:string} $config
     * @param ImapTransport|null $transport test seam / alternative library binding
     */
    public function __construct(array $config, ?ImapTransport $transport = null)
    {
        foreach (['host', 'username', 'password'] as $k) {
            if (!isset($config[$k]) || $config[$k] === '') {
                throw new \InvalidArgumentException("ImapConnector: '{$k}' is required");
            }
        }
        $this->folder        = (string) ($config['folder'] ?? 'INBOX');
        $this->coldStartDays = max(1, (int) ($config['cold_start_days'] ?? 30));
        $this->trashFolder   = isset($config['trash_folder']) && $config['trash_folder'] !== '' ? (string) $config['trash_folder'] : null;
        $this->imap          = $transport ?? new WebklexTransport($config);
    }

    public function capabilities(): array
    {
        return [
            self::CAP_LIST_FOLDERS, self::CAP_FETCH_BODY, self::CAP_BACKFILL,
            self::CAP_MARK_READ, self::CAP_MOVE, self::CAP_TRASH,
        ];
    }

    public function folder(): string
    {
        return $this->folder;
    }

    public function verify(): void
    {
        $this->connect();
        $s = $this->imap->status($this->folder);
        if (($s['uidvalidity'] ?? 0) <= 0) {
            throw new TransientError("IMAP folder {$this->folder} reported no UIDVALIDITY — cannot track it incrementally");
        }
    }

    /** Every folder with its role and whether a client should poll it ({@see FolderRole::assignImap()}). */
    public function listFolders(): array
    {
        $this->connect();
        $folders = $this->imap->folders();
        // FolderRole matches names on the id, and the wire id is modified
        // UTF-7 ("Envoy&AOk-s"): assign on the decoded path, hand back the raw id.
        $raw = array_column($folders, 'id');
        foreach ($folders as &$f) {
            $d    = @mb_convert_encoding((string) ($f['id'] ?? ''), 'UTF-8', 'UTF7-IMAP');
            $f['id'] = is_string($d) && $d !== '' ? $d : (string) ($f['id'] ?? '');
        }
        unset($f);
        $rows = FolderRole::assignImap($folders);
        foreach ($rows as $i => $r) {
            $rows[$i]['id'] = (string) $raw[$i];
        }
        return $rows;
    }

    public function fetchHeaders(string $folder, ?MailboxState $cursor, int $max): FetchResult
    {
        $this->connect();
        $folder = $folder !== '' ? $folder : $this->folder;
        $max    = self::clampMax($max);
        $status = $this->imap->status($folder);
        $uidvalidity = (int) $status['uidvalidity'];
        $serverNext  = (int) $status['uidnext'];

        $reason = null;
        if ($cursor === null || $cursor->uidvalidity() === null || $cursor->uidnext() === null) {
            $reason = FetchResult::REASON_INITIAL;
        } elseif ($cursor->uidvalidity() !== $uidvalidity) {
            $reason = FetchResult::REASON_UIDVALIDITY_CHANGED;
        }

        if ($reason !== null) {
            // Bounded cold start: newest $max of the last N days.
            $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$this->coldStartDays} days");
            $uids  = $this->imap->uids($folder, null, $since);
            sort($uids);
            $complete = count($uids) <= $max;
            $picked   = $complete ? $uids : array_slice($uids, -$max);
            $rows     = $this->rows($folder, $picked);
            // Cold start takes the NEWEST slice; anything older in the window is not coming — flag it.
            $next   = $complete ? max($serverNext, ($picked === [] ? 1 : max($picked) + 1)) : max($picked) + 1;
            $state  = MailboxState::imap($uidvalidity, $next, $folder);
            // `complete` reports whether this listing is the WHOLE window or
            // the truncated newest slice. It used to be hardcoded true even
            // when $complete computed false, which made a truncated listing
            // claim to be exhaustive — and a caller that treats "absent from
            // a complete listing" as "deleted server-side" would then mark
            // every older message in the window. Measured on a 428-message
            // folder with $max = 200: 228 rows would have been marked gone.
            return new FetchResult($rows, $state, $complete, true, $reason);
        }

        $from     = (int) $cursor->uidnext();
        $uids     = $this->imap->uids($folder, $from, null);
        $uids     = array_values(array_filter($uids, static fn ($u) => $u >= $from));
        sort($uids);
        $complete = count($uids) <= $max;
        $picked   = $complete ? $uids : array_slice($uids, 0, $max);
        $rows     = $this->rows($folder, $picked);
        $next     = $complete ? max($serverNext, $from, ($picked === [] ? 0 : max($picked) + 1)) : max($picked) + 1;
        return new FetchResult($rows, MailboxState::imap($uidvalidity, $next, $folder), $complete, false, null);
    }

    /**
     * History walk, downwards. Token: "<uidvalidity>:<uid>" — the OLDEST UID
     * of the page already read, so the next page is everything strictly
     * below it. One unbounded UID search per page (IMAP has no "the N
     * highest UIDs below X" search; the SEARCH itself is cheap next to the
     * per-UID header FETCHes), then the newest $max of what is left.
     *
     * The poll cursor (uidvalidity/uidnext) is never read or written here:
     * the two walks are independent and a backfill can only ever re-read
     * rows the UNIQUE key already rejects.
     */
    public function fetchBefore(string $folder, ?string $before, int $max): BackfillResult
    {
        $this->connect();
        $folder = $folder !== '' ? $folder : $this->folder;
        $max    = self::clampMax($max);
        $uidvalidity = (int) $this->imap->status($folder)['uidvalidity'];

        // A token minted under a dead UIDVALIDITY addresses nothing: start
        // again from the newest message and say so.
        $ceiling   = null;
        $restarted = false;
        if ($before !== null && $before !== '') {
            if (preg_match('/^(\d+):(\d+)$/', $before, $m) && (int) $m[1] === $uidvalidity) {
                $ceiling = (int) $m[2];
            } else {
                $restarted = true;
            }
        }

        $uids = $this->imap->uids($folder, null, null);
        if ($ceiling !== null) {
            $uids = array_filter($uids, static fn ($u) => (int) $u < $ceiling);
        }
        $uids = array_values($uids);
        sort($uids);

        $complete = count($uids) <= $max;
        $picked   = $complete ? $uids : array_slice($uids, -$max); // the NEWEST slice, ascending
        $rows     = $this->rows($folder, $picked);
        $next     = $picked === [] ? null : $uidvalidity . ':' . min($picked);
        return new BackfillResult($rows, $next, $complete, $restarted);
    }

    /**
     * One `UID SEARCH ALL`, no header FETCH. UIDVALIDITY is read before AND
     * after the search: if it moved in between, the UIDs belong to two
     * different id spaces and the listing is reported incomplete rather than
     * guessed at.
     */
    public function listIds(string $folder): FolderListing
    {
        $this->connect();
        $folder = $folder !== '' ? $folder : $this->folder;
        $before = (int) $this->imap->status($folder)['uidvalidity'];
        $uids   = $this->imap->uids($folder, null, null);
        $after  = (int) $this->imap->status($folder)['uidvalidity'];
        $ids = [];
        foreach ($uids as $uid) {
            $ids[self::makeId((int) $uid, $folder)] = true;
        }
        return new FolderListing($ids, (string) $after, $before === $after && $after > 0);
    }

    public function fetchBody(string $providerId): MailBody
    {
        $this->connect();
        [$uid, $folder] = $this->parseId($providerId);
        $raw = $this->imap->raw($folder, $uid);
        return MimeBodyParser::parse($raw, $providerId);
    }

    public function markRead(string $providerId, bool $read): void
    {
        $this->connect();
        [$uid, $folder] = $this->parseId($providerId);
        $this->imap->setSeen($folder, $uid, $read);
    }

    public function move(string $providerId, string $folder): string
    {
        $this->connect();
        [$uid, $from] = $this->parseId($providerId);
        $newUid = $this->imap->move($from, $uid, $folder);
        // No COPYUID: the new uid is unknown. '' says so; a guess would be a
        // provider id that points at nothing (or at another message).
        return $newUid > 0 ? self::makeId($newUid, $folder) : '';
    }

    public function trash(string $providerId): string
    {
        $this->connect();
        $trash = $this->trashFolder ?? $this->detectTrash();
        if ($trash !== null) {
            return $this->move($providerId, $trash);
        }
        [$uid, $folder] = $this->parseId($providerId);
        $this->imap->delete($folder, $uid);
        return $providerId;
    }

    // ------------------------------------------------------------ StateWriter

    public function messageState(string $providerId): ?MessageState
    {
        self::assertResolved($providerId, 'messageState');
        [$uid, $folder] = $this->parseId($providerId);
        $this->connect();
        $flags = $this->imap->flags($folder, $uid);
        if ($flags === null) {
            return null;
        }
        return new MessageState(self::hasFlag($flags, 'Seen'), self::hasFlag($flags, 'Flagged'), $folder, null, $flags);
    }

    public function setFlag(string $providerId, bool $flagged): void
    {
        self::assertResolved($providerId, 'setFlag');
        [$uid, $folder] = $this->parseId($providerId);
        $this->connect();
        $this->imap->setFlagged($folder, $uid, $flagged);
    }

    /**
     * MOVE to $archiveFolder, or — when '' — to the server's own \Archive
     * folder, else its \All folder (never a folder guessed by name). Gmail
     * over IMAP: MOVE to All Mail is how Gmail archives (the Inbox label
     * goes), and a message already in All Mail is left where it is.
     */
    public function archive(string $providerId, string $archiveFolder = ''): string
    {
        self::assertResolved($providerId, 'archive');
        [, $from] = $this->parseId($providerId);
        $this->connect();
        $to = $archiveFolder;
        if ($to === '') {
            $layout = $this->layout();
            $to     = (string) ($layout['archive'] ?? $layout['all'] ?? '');
            if ($to === '') {
                throw $this->unsupported('archive without an Archive folder');
            }
        }
        return $this->relocate($providerId, $from, $to);
    }

    /** Back to $toFolder ('' = the configured folder). Never trash()'s hard-delete path. */
    public function untrash(string $providerId, string $toFolder = ''): string
    {
        self::assertResolved($providerId, 'untrash');
        [, $from] = $this->parseId($providerId);
        $this->connect();
        return $this->relocate($providerId, $from, $toFolder !== '' ? $toFolder : $this->folder);
    }

    /**
     * One message from $from to $to, the state-writer way:
     *  - already there → nothing to do, same id;
     *  - Gmail over IMAP, out of All Mail → UID COPY (adds the label). A MOVE
     *    would expunge it from All Mail, which Gmail may turn into a delete
     *    depending on the account's IMAP settings. Into All Mail stays a
     *    MOVE (= archive: the source label goes);
     *  - otherwise → UID MOVE.
     * The new id comes from COPYUID only; '' when the server did not say.
     */
    private function relocate(string $providerId, string $from, string $to): string
    {
        if (self::sameFolder($from, $to)) {
            return $providerId;
        }
        $layout = $this->layout();
        if ($layout['label_server'] && $layout['all'] !== null && self::sameFolder($from, $layout['all'])) {
            if (self::sameFolder($to, $layout['all'])) {
                return $providerId;
            }
            [$uid] = $this->parseId($providerId);
            $newUid = $this->imap->copy($from, $uid, $to);
            return $newUid > 0 ? self::makeId($newUid, $to) : '';
        }
        return $this->move($providerId, $to);
    }

    /** @return array{label_server:bool, all:?string, archive:?string} */
    private function layout(): array
    {
        return $this->layout ??= FolderRole::imapLayout($this->imap->folders());
    }

    /** INBOX is case-insensitive (RFC 3501); every other path is compared as is. */
    private static function sameFolder(string $a, string $b): bool
    {
        return $a === $b || (strcasecmp($a, 'INBOX') === 0 && strcasecmp($b, 'INBOX') === 0);
    }

    /** 'Seen', '\Seen' and 'seen' name the same flag. */
    private static function hasFlag(array $flags, string $want): bool
    {
        foreach ($flags as $f) {
            if (strcasecmp(ltrim((string) $f, '\\'), $want) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Folder names are compared on a canonical form — '.' and '/' both read
     * as the hierarchy separator, case folded — so "INBOX/SPAM" finds a
     * Dovecot "INBOX.Spam" and "inbox.spam" finds a Gmail-style "INBOX/Spam".
     * The first match wins (a server holding both "A.B" and "A/B" as distinct
     * names is not one this can tell apart). When nothing matches the path is
     * rebuilt in the server's own delimiter — INBOX's when the server lists
     * it (sub-folders inherit it), else the first folder's, else "/" — with a
     * leading "inbox" segment spelt "INBOX" (RFC 3501: INBOX is
     * case-insensitive, the rest is not), then CREATEd.
     */
    public function ensureFolder(string $wanted): string
    {
        $this->connect();
        $key = self::folderKey($wanted);
        if ($key === '') {
            throw new \InvalidArgumentException('ImapConnector::ensureFolder: empty folder name');
        }
        $folders = $this->imap->folders();
        foreach ($folders as $f) {
            if (self::folderKey((string) $f['id']) === $key) {
                return (string) $f['id'];
            }
        }

        $delimiter = null;
        foreach ($folders as $f) {
            $d = (string) ($f['delimiter'] ?? '');
            if ($d === '') continue;
            $delimiter ??= $d; // first folder's, unless INBOX says otherwise
            if (strcasecmp((string) $f['id'], 'INBOX') === 0 || stripos((string) $f['id'], 'INBOX' . $d) === 0) {
                $delimiter = $d;
                break;
            }
        }
        $delimiter ??= '/';

        $parts = array_values(array_filter(array_map('trim', preg_split('#[./]#', $wanted)), static fn ($p) => $p !== ''));
        if (strcasecmp($parts[0], 'INBOX') === 0) $parts[0] = 'INBOX';
        $path = implode($delimiter, $parts);
        $this->imap->createFolder($path);
        // Subscribe it, and every parent the CREATE implied: desktop clients
        // (Thunderbird) list subscribed folders only, so a folder we create
        // and never subscribe is mail the user cannot see.
        for ($i = 1; $i <= count($parts); $i++) {
            try {
                $this->imap->subscribe(implode($delimiter, array_slice($parts, 0, $i)));
            } catch (\Throwable) {
                // Best effort: INBOX may refuse, a parent may not exist as a
                // real folder. The folder itself was created either way.
            }
        }
        return $path;
    }

    public function fetchRaw(string $providerId): string
    {
        $this->connect();
        [$uid, $folder] = $this->parseId($providerId);
        return $this->imap->raw($folder, $uid);
    }

    /**
     * Header block only (BODY.PEEK[HEADER]): no body, no attachments, \\Seen
     * untouched. A provider id that is not an IMAP "uid:folder" (e.g. an
     * "unresolved:" placeholder) is refused like every other method here.
     */
    public function fetchRawHeader(string $providerId): string
    {
        [$uid, $folder] = $this->parseId($providerId);
        $this->connect();
        return $this->imap->rawHeader($folder, $uid);
    }

    public function append(string $folder, string $raw, bool $seen): string
    {
        $this->connect();
        $folder = $folder !== '' ? $folder : $this->folder;
        $uid    = $this->imap->append($folder, $raw, $seen);
        return $uid > 0 ? self::makeId($uid, $folder) : '';
    }

    /** "INBOX/Spam", "inbox.spam", " INBOX / SPAM " → "inbox/spam" */
    private static function folderKey(string $name): string
    {
        $parts = array_filter(array_map('trim', preg_split('#[./]#', $name)), static fn ($p) => $p !== '');
        return strtolower(implode('/', $parts));
    }

    public static function makeId(int $uid, string $folder): string
    {
        return $uid . ':' . $folder;
    }

    /** @return array{0:int, 1:string} */
    public function parseId(string $providerId): array
    {
        if (!preg_match('/^(\d+)(?::(.*))?$/s', $providerId, $m)) {
            throw new \InvalidArgumentException("Malformed IMAP provider id: {$providerId}");
        }
        $folder = isset($m[2]) && $m[2] !== '' ? $m[2] : $this->folder;
        return [(int) $m[1], $folder];
    }

    /**
     * @param int[] $uids
     * @return array<int,array<string,mixed>>
     */
    private function rows(string $folder, array $uids): array
    {
        if ($uids === []) return [];
        $raw = $this->imap->headers($folder, $uids);
        $out = [];
        foreach ($uids as $uid) {
            if (!isset($raw[$uid])) continue; // vanished between search and fetch
            $out[] = self::normalise($raw[$uid], $folder);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $r a transport header row
     * @return array<string,mixed>
     */
    public static function normalise(array $r, string $folder): array
    {
        $from = HeaderRecord::parseAddress((string) ($r['from'] ?? ''));
        return HeaderRecord::normalise([
            'provider_message_id' => self::makeId((int) $r['uid'], $folder),
            'thread_id'           => $r['thread_id'] ?? null, // Gmail X-GM-THRID when the transport could read it
            'message_id_header'   => $r['message_id'] ?? null,
            'in_reply_to'         => $r['in_reply_to'] ?? null,
            'from_addr'           => $from['addr'],
            'from_name'           => $from['name'],
            'to'                  => (string) ($r['to'] ?? ''),
            'cc'                  => (string) ($r['cc'] ?? ''),
            'subject'             => HeaderRecord::decodeWords((string) ($r['subject'] ?? '')),
            'date_sent'           => $r['date'] ?? null,
            'snippet'             => (string) ($r['snippet'] ?? ''),
            'size_bytes'          => (int) ($r['size'] ?? 0),
            'has_attachments'     => (bool) ($r['has_attachments'] ?? false),
            'folder_at_fetch'     => $folder,
            'was_read_at_fetch'   => (bool) ($r['seen'] ?? false),
            'labels'              => (array) ($r['flags'] ?? []),
            'auth_results'        => (string) ($r['auth_results'] ?? ''), // topmost Authentication-Results only
            'list_id'             => (string) ($r['list_id'] ?? ''),
            'list_unsubscribe'    => (string) ($r['list_unsubscribe'] ?? ''),
            'precedence'          => (string) ($r['precedence'] ?? ''),
            'auto_submitted'      => (string) ($r['auto_submitted'] ?? ''),
            'references'          => (string) ($r['references'] ?? ''),
            'reply_to'            => (string) ($r['reply_to'] ?? ''),
        ]);
    }

    private function detectTrash(): ?string
    {
        foreach ($this->imap->folders() as $f) {
            $name = strtolower((string) ($f['name'] ?? $f['id']));
            if (preg_match('/(^|[.\/])(trash|deleted items|deleted messages|bin|corbeille)$/', $name)) {
                return (string) $f['id'];
            }
        }
        return null;
    }

    private function connect(): void
    {
        if (!$this->connected) {
            $this->imap->connect();
            $this->connected = true;
        }
    }

    public function __destruct()
    {
        if ($this->connected) {
            try { $this->imap->disconnect(); } catch (\Throwable) {}
        }
    }
}
