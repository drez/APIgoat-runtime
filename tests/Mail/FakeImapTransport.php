<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\Imap\ImapTransport;

/** In-memory IMAP server: folders → uid → row. Records every call. */
class FakeImapTransport implements ImapTransport
{
    public int $uidvalidity = 1000;
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $store = [];
    /** @var string[] */
    public array $log = [];
    public bool $connected = false;
    public ?\Throwable $connectError = null;
    /** Hierarchy delimiter reported for every folder ('' = report none). */
    public string $delimiter = '/';
    /** @var array<string,string[]> folder => LIST attributes as the server sends them ("\\Sent") */
    public array $attributes = [];
    /** @var array<string,bool> folder => LSUB membership; absent = unknown */
    public array $subscribed = [];

    public function connect(): void
    {
        $this->log[] = 'connect';
        if ($this->connectError) throw $this->connectError;
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->log[] = 'disconnect';
        $this->connected = false;
    }

    public function folders(): array
    {
        $this->log[] = 'folders';
        return array_map(
            fn ($f) => ['id' => (string) $f, 'name' => (string) $f]
                + ($this->delimiter !== '' ? ['delimiter' => $this->delimiter] : [])
                + ['attributes' => $this->attributes[$f] ?? [], 'subscribed' => $this->subscribed[$f] ?? null],
            array_keys($this->store)
        );
    }

    public function createFolder(string $path): void
    {
        $this->log[] = "create:$path";
        $this->store[$path] ??= [];
    }

    public function subscribe(string $path): void
    {
        $this->log[] = "subscribe:$path";
    }

    public function append(string $folder, string $raw, bool $seen): int
    {
        $this->log[] = "append:$folder:" . ($seen ? '1' : '0');
        $new = ($this->store[$folder] ?? []) ? max(array_keys($this->store[$folder])) + 1 : 1;
        $this->add($folder, $new, ['raw' => $raw, 'seen' => $seen, 'flags' => $seen ? ['Seen'] : []]);
        return $new;
    }

    public function status(string $folder): array
    {
        $this->log[] = "status:$folder";
        $uids = array_keys($this->store[$folder] ?? []);
        return ['uidvalidity' => $this->uidvalidity, 'uidnext' => $uids ? max($uids) + 1 : 1, 'exists' => count($uids)];
    }

    public function uids(string $folder, ?int $minUid, ?\DateTimeInterface $since): array
    {
        $this->log[] = "uids:$folder:" . ($minUid ?? '-') . ':' . ($since ? $since->format('Y-m-d') : '-');
        $out = [];
        foreach ($this->store[$folder] ?? [] as $uid => $row) {
            if ($minUid !== null && $uid < $minUid) continue;
            if ($since !== null && strtotime((string) $row['date']) < $since->getTimestamp()) continue;
            $out[] = $uid;
        }
        sort($out);
        return $out;
    }

    public function headers(string $folder, array $uids): array
    {
        $this->log[] = "headers:$folder:" . implode(',', $uids);
        $out = [];
        foreach ($uids as $u) {
            if (isset($this->store[$folder][$u])) $out[$u] = $this->store[$folder][$u] + ['uid' => $u];
        }
        return $out;
    }

    public function raw(string $folder, int $uid): string
    {
        $this->log[] = "raw:$folder:$uid";
        return (string) ($this->store[$folder][$uid]['raw'] ?? '');
    }

    public function setSeen(string $folder, int $uid, bool $seen): void
    {
        $this->log[] = "seen:$folder:$uid:" . ($seen ? '1' : '0');
        $this->store[$folder][$uid]['seen'] = $seen;
    }

    public function flags(string $folder, int $uid): ?array
    {
        $this->log[] = "flags:$folder:$uid";
        if (!isset($this->store[$folder][$uid])) return null;
        $row   = $this->store[$folder][$uid];
        $flags = array_values(array_diff((array) ($row['flags'] ?? []), ['Seen']));
        if (!empty($row['seen'])) $flags[] = 'Seen';
        return $flags;
    }

    public function setFlagged(string $folder, int $uid, bool $flagged): void
    {
        $this->log[] = "flagged:$folder:$uid:" . ($flagged ? '1' : '0');
        $flags = array_values(array_diff((array) ($this->store[$folder][$uid]['flags'] ?? []), ['Flagged', '\\Flagged']));
        if ($flagged) $flags[] = 'Flagged';
        $this->store[$folder][$uid]['flags'] = $flags;
    }

    /** Reported by move() AND copy(): false = a server without UIDPLUS (no COPYUID). */
    public bool $reportMoveUid = true;

    public function copy(string $folder, int $uid, string $destination): int
    {
        $this->log[] = "copy:$folder:$uid:$destination";
        $row = $this->store[$folder][$uid];
        $new = ($this->store[$destination] ?? []) ? max(array_keys($this->store[$destination])) + 1 : 1;
        $this->store[$destination][$new] = $row;
        return $this->reportMoveUid ? $new : 0;
    }

    public function move(string $folder, int $uid, string $destination): int
    {
        $this->log[] = "move:$folder:$uid:$destination";
        $row = $this->store[$folder][$uid];
        unset($this->store[$folder][$uid]);
        $new = ($this->store[$destination] ?? []) ? max(array_keys($this->store[$destination])) + 1 : 1;
        $this->store[$destination][$new] = $row;
        return $this->reportMoveUid ? $new : 0;
    }

    public function delete(string $folder, int $uid): void
    {
        $this->log[] = "delete:$folder:$uid";
        unset($this->store[$folder][$uid]);
    }

    public function add(string $folder, int $uid, array $row = []): void
    {
        $this->store[$folder][$uid] = $row + [
            'message_id' => "<m{$uid}@x>", 'in_reply_to' => '', 'from' => "Sender {$uid} <s{$uid}@x.com>",
            'to' => 'me@x.com', 'cc' => '', 'subject' => "Subject {$uid}", 'date' => gmdate('r', time() - 3600),
            'size' => 100 + $uid, 'has_attachments' => false, 'seen' => false, 'flags' => [],
            'auth_results' => '',
        ];
    }
}
