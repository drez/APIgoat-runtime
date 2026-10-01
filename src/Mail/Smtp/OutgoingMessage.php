<?php

namespace ApiGoat\Mail\Smtp;

/** One message to build (MimeBuilder) and submit (SmtpTransport). Address lists: list<array{addr:string,name:string}>. */
final class OutgoingMessage
{
    /**
     * @param array<int,array{addr:string,name:string}> $to
     * @param array<int,array{addr:string,name:string}> $cc
     * @param array<int,array{addr:string,name:string}> $bcc
     * @param array<int,array{filename:string, mime:string, content:string, content_id?:?string}> $attachments
     */
    public function __construct(
        public readonly string $fromAddr,
        public readonly string $fromName,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html,
        public readonly string $messageId,
        public readonly ?string $inReplyTo = null,
        public readonly ?string $references = null,
        public readonly array $attachments = [],
        public readonly ?\DateTimeInterface $date = null,
    ) {
    }

    /** @return string[] every To/Cc/Bcc address, lower-cased, first occurrence kept */
    public function envelope(): array
    {
        $out = [];
        foreach (array_merge($this->to, $this->cc, $this->bcc) as $a) {
            $addr = strtolower(trim((string) ($a['addr'] ?? '')));
            if ($addr !== '' && !in_array($addr, $out, true)) {
                $out[] = $addr;
            }
        }
        return $out;
    }
}
