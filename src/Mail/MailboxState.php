<?php

namespace ApiGoat\Mail;

/**
 * Incremental-fetch cursor, persisted as `mailbox.state_json`.
 *
 *   IMAP  → uidvalidity + uidnext (next UID we have NOT seen yet)
 *   Gmail → history_id (+ page_token while a cold start is still paging)
 *
 * Opaque to callers: persist {@see toJson()} after the rows it covers are
 * committed, hand {@see fromJson()} back on the next call. Extra keys are
 * kept round-trip so a connector can stash provider-specific bits.
 */
final class MailboxState implements \JsonSerializable
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = [])
    {
    }

    public static function imap(int $uidvalidity, int $uidnext, ?string $folder = null): self
    {
        $d = ['uidvalidity' => $uidvalidity, 'uidnext' => $uidnext];
        if ($folder !== null) $d['folder'] = $folder;
        return new self($d);
    }

    public static function gmail(string $historyId, ?string $pageToken = null): self
    {
        $d = ['history_id' => $historyId];
        if ($pageToken !== null && $pageToken !== '') $d['page_token'] = $pageToken;
        return new self($d);
    }

    public static function fromJson(?string $json): ?self
    {
        if ($json === null || trim($json) === '') return null;
        $d = json_decode($json, true);
        if (!is_array($d) || $d === []) return null;
        return new self($d);
    }

    /** @param array<string,mixed>|null $data */
    public static function fromArray(?array $data): ?self
    {
        return $data ? new self($data) : null;
    }

    public function toJson(): string
    {
        return (string) json_encode($this->data, JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function with(string $key, mixed $value): self
    {
        $d = $this->data;
        if ($value === null) unset($d[$key]); else $d[$key] = $value;
        return new self($d);
    }

    public function uidvalidity(): ?int
    {
        return isset($this->data['uidvalidity']) ? (int) $this->data['uidvalidity'] : null;
    }

    public function uidnext(): ?int
    {
        return isset($this->data['uidnext']) ? (int) $this->data['uidnext'] : null;
    }

    public function historyId(): ?string
    {
        $h = $this->data['history_id'] ?? null;
        return ($h === null || $h === '') ? null : (string) $h;
    }

    public function pageToken(): ?string
    {
        $t = $this->data['page_token'] ?? null;
        return ($t === null || $t === '') ? null : (string) $t;
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }
}
