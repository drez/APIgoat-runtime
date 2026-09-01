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
        return array_map(fn ($f) => ['id' => $f, 'name' => $f], array_keys($this->store));
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

    public function move(string $folder, int $uid, string $destination): int
    {
        $this->log[] = "move:$folder:$uid:$destination";
        $row = $this->store[$folder][$uid];
        unset($this->store[$folder][$uid]);
        $new = ($this->store[$destination] ?? []) ? max(array_keys($this->store[$destination])) + 1 : 1;
        $this->store[$destination][$new] = $row;
        return $new;
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
            'to' => 'me@x.com', 'cc' => '', 'subject' => "Subject {$uid}", 'date' => 'Mon, 31 Aug 2026 10:00:00 +0000',
            'size' => 100 + $uid, 'has_attachments' => false, 'seen' => false, 'flags' => [],
        ];
    }
}
