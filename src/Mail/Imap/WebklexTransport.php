<?php

namespace ApiGoat\Mail\Imap;

use ApiGoat\Mail\HeaderRecord;
use ApiGoat\Sync\Exceptions\TransientError;
use ApiGoat\Sync\Exceptions\ValidationRejected;

/**
 * {@see ImapTransport} over webklex/php-imap (pure PHP — `ext-imap` is not
 * installed on the fleet). The library is a `suggest`, not a `require`:
 * this file autoloads without it and {@see available()} says whether it
 * can actually be used. Every library call goes through {@see guard()} so
 * library exceptions never escape as library types.
 *
 * Library-dependent — exercised only against a live server (the apigmail
 * project's smoke), never in the runtime unit tests.
 */
final class WebklexTransport implements ImapTransport
{
    private ?object $client = null;
    /** @var array<string,object> */
    private array $folderCache = [];

    /**
     * @param array{host:string, port?:int, encryption?:string, username:string, password:string,
     *               validate_cert?:bool, authentication?:string, timeout?:int} $config
     */
    public function __construct(private array $config)
    {
    }

    public static function available(): bool
    {
        return class_exists(\Webklex\PHPIMAP\ClientManager::class);
    }

    public function connect(): void
    {
        if (!self::available()) {
            throw new TransientError('webklex/php-imap is not installed — `composer require webklex/php-imap` in the project');
        }
        if ($this->client && $this->client->isConnected()) {
            return;
        }
        $this->guard(function () {
            $cm = new \Webklex\PHPIMAP\ClientManager([]);
            $this->client = $cm->make([
                'host'           => (string) $this->config['host'],
                'port'           => (int) ($this->config['port'] ?? 993),
                'encryption'     => (string) ($this->config['encryption'] ?? 'ssl'),
                'validate_cert'  => (bool) ($this->config['validate_cert'] ?? true),
                'username'       => (string) $this->config['username'],
                'password'       => (string) $this->config['password'],
                'authentication' => $this->config['authentication'] ?? null,
                'protocol'       => 'imap',
                'timeout'        => (int) ($this->config['timeout'] ?? 30),
            ]);
            $this->client->connect();
        }, 'connect');
    }

    public function disconnect(): void
    {
        if ($this->client) {
            try { $this->client->disconnect(); } catch (\Throwable) {}
            $this->client = null;
            $this->folderCache = [];
        }
    }

    /**
     * One flat `LIST "" *`. The hierarchical form (getFolders(true)) nests
     * sub-folders under their parent's `children`, so iterating it only saw
     * the top level — "INBOX.Spam" on a Dovecot/Courier server was invisible
     * to detectTrash() and to FolderWriter::ensureFolder(), which would then
     * try to CREATE a folder that already exists. `delimiter` comes from the
     * LIST response itself (webklex falls back to options.delimiter, "/",
     * only when the server sends NIL).
     */
    public function folders(): array
    {
        return $this->guard(function () {
            // Straight through the protocol: webklex's Folder object keeps
            // \Noselect/\Marked/\HasChildren and DROPS the RFC 6154
            // special-use attributes (\Sent \Junk \Trash \All …), which are
            // the whole point here.
            $conn = $this->client->getConnection();
            $list = (array) $conn->folders('', '*')->validatedData();
            $subs = $this->subscribedPaths();
            $out  = [];
            foreach ($list as $path => $item) {
                $path  = (string) $path;
                $out[] = [
                    'id'         => $path,
                    'name'       => self::decodeName($path),
                    'delimiter'  => (string) ($item['delimiter'] ?? '/'),
                    'attributes' => array_values(array_map('strval', (array) ($item['flags'] ?? []))),
                    'subscribed' => $subs === null ? null : isset($subs[$path]),
                ];
            }
            return $out;
        }, 'list folders');
    }

    /** LSUB "" "*" → path => true; null when the server refuses (unknown, not "none"). @return array<string,true>|null */
    private function subscribedPaths(): ?array
    {
        try {
            $conn = $this->client->getConnection();
            $resp = $conn->requestAndResponse('LSUB', $conn->escapeString('', '*'))->setCanBeEmpty(true);
            $out  = [];
            foreach ((array) $resp->data() as $item) {
                if (is_array($item) && count($item) === 4 && strtoupper((string) $item[0]) === 'LSUB') {
                    $out[str_replace(['\\\\', '\\"'], ['\\', '"'], (string) $item[3])] = true;
                }
            }
            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Modified UTF-7 (RFC 3501 §5.1.3) → UTF-8 for display; the raw path stays the id. */
    private static function decodeName(string $path): string
    {
        $d = @mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP');
        return is_string($d) && $d !== '' ? $d : $path;
    }

    public function createFolder(string $path): void
    {
        $this->guard(function () use ($path) {
            // $expunge=false: CREATE has nothing to expunge, and webklex's
            // expunge() acts on whatever folder happens to be selected.
            $this->client->createFolder($path, false);
            unset($this->folderCache[$path]);
        }, "create folder {$path}");
    }

    public function subscribe(string $path): void
    {
        $this->guard(function () use ($path) {
            // Straight through the protocol: webklex's Folder::subscribe()
            // needs a Folder object, which a \Noselect parent ("ai" above
            // "ai.spam") may not yield.
            $this->client->getConnection()->subscribeFolder($path)->validatedData();
        }, "subscribe {$path}");
    }

    /**
     * webklex's Folder::appendMessage() sends APPEND unparsed and hands back
     * the raw response lines; the tagged OK line carries the new UID as
     * `[APPENDUID <uidvalidity> <uid>]` on UIDPLUS servers (Gmail, Dovecot,
     * Cyrus, Exchange 2013+). Anything else → 0, and the caller re-finds the
     * message by Message-ID if it needs to.
     */
    public function append(string $folder, string $raw, bool $seen): int
    {
        return $this->guard(function () use ($folder, $raw, $seen) {
            $resp = $this->folder($folder)->appendMessage($raw, $seen ? ['\\Seen'] : null);
            return self::appendUid($resp);
        }, "append {$folder}");
    }

    /** @param mixed $resp webklex's validated APPEND response (nested arrays / strings) */
    public static function appendUid(mixed $resp): int
    {
        $text    = '';
        $wrapped = [$resp];
        array_walk_recursive($wrapped, static function ($v) use (&$text) {
            if (is_scalar($v)) $text .= ' ' . $v;
        });
        return preg_match('/APPENDUID\s+\d+\s+(\d+)/i', $text, $m) ? (int) $m[1] : 0;
    }

    public function status(string $folder): array
    {
        return $this->guard(function () use ($folder) {
            $f = $this->folder($folder);
            $s = array_change_key_case((array) $f->status(), CASE_LOWER);
            if (!isset($s['uidvalidity'])) {
                $s = array_change_key_case((array) $f->examine(), CASE_LOWER);
            }
            return [
                'uidvalidity' => (int) ($s['uidvalidity'] ?? 0),
                'uidnext'     => (int) ($s['uidnext'] ?? 0),
                'exists'      => (int) ($s['exists'] ?? $s['messages'] ?? 0),
            ];
        }, "status {$folder}");
    }

    public function uids(string $folder, ?int $minUid, ?\DateTimeInterface $since): array
    {
        return $this->guard(function () use ($folder, $minUid, $since) {
            $q = $this->folder($folder)->query()->setFetchBody(false)->setFetchFlags(false)->leaveUnread();
            if ($since) {
                $q->since($since->format('d-M-Y'));
            } else {
                $q->all();
            }
            $ids = $q->search();
            $uids = [];
            // webklex Query::search() returns a Collection whose KEYS are
            // positions (0,1,2…) and whose VALUES are the UIDs as strings
            // ("38055"). Verified live against Gmail 2026-09-01: reading the
            // keys fetched UIDs 1,2,3 and every poll came back empty.
            foreach ($ids as $v) {
                $u = is_numeric($v) ? (int) $v : 0;
                if ($u > 0 && ($minUid === null || $u >= $minUid)) $uids[] = $u;
            }
            sort($uids);
            return array_values(array_unique($uids));
        }, "search {$folder}");
    }

    public function headers(string $folder, array $uids): array
    {
        if ($uids === []) return [];
        return $this->guard(function () use ($folder, $uids) {
            $f   = $this->folder($folder);
            $out = [];
            foreach ($uids as $uid) {
                $m = $f->query()->setFetchBody(false)->setFetchFlags(true)->leaveUnread()->getMessageByUid((int) $uid);
                if (!$m) continue;
                $flags = [];
                foreach ($m->getFlags() as $flag) $flags[] = (string) $flag;
                $out[(int) $uid] = [
                    'uid'             => (int) $uid,
                    'message_id'      => (string) $m->getMessageId(),
                    'in_reply_to'     => (string) $m->getInReplyTo(),
                    'from'            => self::addr($m->getFrom()),
                    'to'              => self::addr($m->getTo()),
                    'cc'              => self::addr($m->getCc()),
                    'subject'         => (string) $m->getSubject(),
                    'date'            => (string) $m->getDate(),
                    'size'            => (int) $m->getSize(),
                    'has_attachments' => (bool) $m->hasAttachments(),
                    'seen'            => in_array('Seen', $flags, true),
                    'flags'           => $flags,
                    'thread_id'       => '',
                    // The header fetch already carries the whole raw header
                    // block; the TOPMOST Authentication-Results is read from
                    // it by position, never from webklex's parsed get() (which
                    // merges repeats, and the order is the trust).
                    'auth_results'    => HeaderRecord::topmostHeader((string) ($m->getHeader()?->raw ?? ''), HeaderRecord::AUTH_RESULTS_HEADER),
                ];
            }
            foreach ($this->gmailThreadIds($folder, array_keys($out)) as $uid => $thrid) {
                $out[$uid]['thread_id'] = $thrid;
            }
            return $out;
        }, "fetch headers {$folder}");
    }

    /**
     * Gmail's conversation id (the X-GM-THRID IMAP extension) for a batch of
     * uids in ONE read-only FETCH — best effort: a server without the Gmail
     * extensions answers BAD, which is swallowed here (no thread ids, rows
     * unaffected). Verified live against imap.gmail.com 2026-09-01 with the
     * batch form of fetch(); the single-uid form leaves the protocol reader
     * out of step, so even one uid goes through as a one-element array.
     *
     * @param int[] $uids
     * @return array<int,string> uid => thread id (digits)
     */
    private function gmailThreadIds(string $folder, array $uids): array
    {
        if ($uids === []) return [];
        try {
            $this->client->openFolder($folder);
            $resp = $this->client->getConnection()->fetch(['X-GM-THRID'], array_values(array_map('intval', $uids)), null, \Webklex\PHPIMAP\IMAP::ST_UID);
            $out = [];
            foreach ((array) $resp->data() as $uid => $v) {
                if (is_array($v)) $v = $v['X-GM-THRID'] ?? reset($v);
                $v = trim((string) $v);
                if ($v !== '' && ctype_digit($v)) $out[(int) $uid] = $v;
            }
            return $out;
        } catch (\Throwable) {
            return []; // not Gmail (or a transient hiccup): thread_id stays ''
        }
    }

    public function raw(string $folder, int $uid): string
    {
        return $this->guard(function () use ($folder, $uid) {
            $m = $this->folder($folder)->query()->setFetchBody(true)->leaveUnread()->getMessageByUid($uid);
            // Gone (deleted/archived/expunged), not a transient hiccup: never retry.
            if (!$m) throw new ValidationRejected("IMAP uid {$uid} not found in {$folder}", 404);
            return (string) $m->getHeader()->raw . "\r\n\r\n" . (string) $m->getRawBody();
        }, "fetch body {$folder}/{$uid}");
    }

    public function setSeen(string $folder, int $uid, bool $seen): void
    {
        $this->guard(function () use ($folder, $uid, $seen) {
            $m = $this->folder($folder)->query()->setFetchBody(false)->leaveUnread()->getMessageByUid($uid);
            if (!$m) throw new TransientError("IMAP uid {$uid} not found in {$folder}", 404);
            $seen ? $m->setFlag('Seen') : $m->unsetFlag('Seen');
        }, "flag {$folder}/{$uid}");
    }

    public function move(string $folder, int $uid, string $destination): int
    {
        return $this->guard(function () use ($folder, $uid, $destination) {
            // Existence first: UID MOVE of a missing uid is a silent no-op, and
            // callers rely on "not found" (404) to stop retrying an undo.
            $m = $this->folder($folder)->query()->setFetchBody(false)->leaveUnread()->getMessageByUid($uid);
            if (!$m) throw new TransientError("IMAP uid {$uid} not found in {$folder}", 404);
            // Raw MOVE: webklex's Message::move() re-fetches the destination's
            // pre-move UIDNEXT and returned the SOURCE uid on Dovecot and Gmail
            // (measured on every recorded move). Only COPYUID proves the new uid.
            // Safe by construction: any failure throws (validatedData) and an
            // unreported uid is 0, which ImapConnector maps to ''.
            // Known limit: when the server refuses MOVE, webklex falls back to
            // COPY+STORE+EXPUNGE and returns the EXPUNGE response, so COPYUID is
            // lost and this returns 0 ('' upstream) -- the safe outcome. Reaching
            // the COPY response would mean re-implementing that fallback here.
            $this->client->openFolder($folder);
            $resp = $this->client->getConnection()->moveMessage($destination, $uid, null, \Webklex\PHPIMAP\IMAP::ST_UID);
            $resp->validatedData();
            unset($this->folderCache[$destination]);
            return self::copyUid([$resp->data(), $resp->getResponse()], $uid);
        }, "move {$folder}/{$uid}");
    }

    /** [COPYUID <uidvalidity> <src-set> <dst-set>] (RFC 4315) -> the destination uid of $srcUid; 0 when not reported. */
    public static function copyUid(mixed $resp, int $srcUid): int
    {
        $text    = '';
        $wrapped = [$resp];
        array_walk_recursive($wrapped, static function ($v) use (&$text) {
            if (is_scalar($v)) $text .= ' ' . $v;
        });
        if (!preg_match('/COPYUID\s+\d+\s+([\d:,]+)\s+([\d:,]+)/i', $text, $m)) {
            return 0;
        }
        $src = self::expandSet($m[1]);
        $dst = self::expandSet($m[2]);
        $i   = array_search($srcUid, $src, true);
        return ($i !== false && isset($dst[$i])) ? $dst[$i] : 0;
    }

    /** "4,7:9" -> [4,7,8,9] @return int[] */
    private static function expandSet(string $set): array
    {
        $out = [];
        foreach (explode(',', $set) as $part) {
            if (str_contains($part, ':')) {
                [$a, $b] = array_map('intval', explode(':', $part, 2));
                for ($x = min($a, $b); $x <= max($a, $b); $x++) $out[] = $x;
            } else {
                $out[] = (int) $part;
            }
        }
        return $out;
    }

    public function delete(string $folder, int $uid): void
    {
        $this->guard(function () use ($folder, $uid) {
            $m = $this->folder($folder)->query()->setFetchBody(false)->leaveUnread()->getMessageByUid($uid);
            if (!$m) throw new TransientError("IMAP uid {$uid} not found in {$folder}", 404);
            $m->delete(true);
        }, "delete {$folder}/{$uid}");
    }

    private function folder(string $path): object
    {
        if (!isset($this->folderCache[$path])) {
            $f = $this->client->getFolderByPath($path);
            if (!$f) throw new TransientError("IMAP folder not found: {$path}", 404);
            $this->folderCache[$path] = $f;
        }
        return $this->folderCache[$path];
    }

    /** @param mixed $list the library's address collection/array → one raw header string */
    private static function addr(mixed $list): string
    {
        $parts = [];
        // webklex hands back a Webklex\PHPIMAP\Attribute (not iterable by
        // is_iterable()) wrapping Address objects — unwrap it first.
        if (is_object($list) && method_exists($list, 'toArray')) {
            $list = $list->toArray();
        }
        foreach ((is_iterable($list) ? $list : []) as $a) {
            $mail = (string) ($a->mail ?? '');
            $name = (string) ($a->personal ?? '');
            if ($mail === '') continue;
            $parts[] = $name !== '' ? '"' . str_replace('"', '', $name) . '" <' . $mail . '>' : $mail;
        }
        return implode(', ', $parts);
    }

    private function guard(callable $fn, string $context): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            throw ImapExceptionMapper::map($e, 'IMAP ' . $context);
        }
    }
}
