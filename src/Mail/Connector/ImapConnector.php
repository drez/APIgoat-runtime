<?php

namespace ApiGoat\Mail\Connector;

use ApiGoat\Mail\BaseConnector;
use ApiGoat\Mail\FetchResult;
use ApiGoat\Mail\HeaderRecord;
use ApiGoat\Mail\Imap\ImapTransport;
use ApiGoat\Mail\Imap\WebklexTransport;
use ApiGoat\Mail\MailBody;
use ApiGoat\Mail\MailboxState;
use ApiGoat\Mail\MailConnector;
use ApiGoat\Mail\MimeBodyParser;
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
class ImapConnector extends BaseConnector
{
    private ImapTransport $imap;
    private string $folder;
    private int $coldStartDays;
    private ?string $trashFolder;
    private bool $connected = false;

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
            self::CAP_LIST_FOLDERS, self::CAP_FETCH_BODY,
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

    public function listFolders(): array
    {
        $this->connect();
        return $this->imap->folders();
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
            return new FetchResult($rows, $state, true, true, $reason);
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
        return self::makeId($newUid > 0 ? $newUid : $uid, $folder);
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
            'thread_id'           => null,
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
