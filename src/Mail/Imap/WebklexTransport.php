<?php

namespace ApiGoat\Mail\Imap;

use ApiGoat\Sync\Exceptions\TransientError;

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

    public function folders(): array
    {
        return $this->guard(function () {
            $out = [];
            foreach ($this->client->getFolders(true) as $f) {
                $out[] = ['id' => (string) $f->path, 'name' => (string) ($f->full_name ?? $f->name ?? $f->path)];
            }
            return $out;
        }, 'list folders');
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
                ];
            }
            return $out;
        }, "fetch headers {$folder}");
    }

    public function raw(string $folder, int $uid): string
    {
        return $this->guard(function () use ($folder, $uid) {
            $m = $this->folder($folder)->query()->setFetchBody(true)->leaveUnread()->getMessageByUid($uid);
            if (!$m) throw new TransientError("IMAP uid {$uid} not found in {$folder}", 404);
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
            $m = $this->folder($folder)->query()->setFetchBody(false)->leaveUnread()->getMessageByUid($uid);
            if (!$m) throw new TransientError("IMAP uid {$uid} not found in {$folder}", 404);
            $moved = $m->move($destination);
            // MOVE/COPYUID responses give the new uid; the library returns the moved Message when it can.
            if (is_object($moved) && method_exists($moved, 'getUid')) return (int) $moved->getUid();
            return 0;
        }, "move {$folder}/{$uid}");
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
