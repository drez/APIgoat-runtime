<?php

namespace ApiGoat\Mail;

/**
 * One message's content. Attachments are DESCRIBED (name/mime/size), never
 * downloaded — phase-1 triage reads text; a later phase can fetch parts.
 */
final class MailBody
{
    /**
     * @param array<int,array{filename:string, mime:string, size:int, part_id?:string}> $attachments
     * @param array<string,string> $headers raw header map (lower-cased names) when the provider gives them
     */
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $text,
        public readonly string $html,
        public readonly array $attachments = [],
        public readonly array $headers = [],
        public readonly int $sizeBytes = 0,
    ) {
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }

    /** Best plain-text rendering: text/plain, else flattened HTML. */
    public function plainText(): string
    {
        if (trim($this->text) !== '') return $this->text;
        return self::htmlToText($this->html);
    }

    public static function htmlToText(string $html): string
    {
        if ($html === '') return '';
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</p>|</div>|</tr>|</li>|</h[1-6]>#i', "\n", $html) ?? $html;
        $txt  = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt  = preg_replace("/[ \t]+\n/", "\n", $txt) ?? $txt;
        $txt  = preg_replace("/\n{3,}/", "\n\n", $txt) ?? $txt;
        return trim($txt);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'provider_message_id' => $this->providerMessageId,
            'text'                => $this->text,
            'html'                => $this->html,
            'attachments'         => $this->attachments,
            'headers'             => $this->headers,
            'size_bytes'          => $this->sizeBytes,
        ];
    }
}
