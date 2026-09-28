<?php

namespace ApiGoat\Mail;

/**
 * Every provider id a folder holds RIGHT NOW — ids only, no headers. What
 * {@see FolderLister::listIds()} returns.
 *
 *  - ids         provider id => true, in the exact form fetchHeaders() puts
 *                in `provider_message_id`, so a caller can diff it against
 *                its stored rows
 *  - generation  the id space these ids belong to: the IMAP UIDVALIDITY as a
 *                string, null for a provider whose ids never renumber
 *                (Gmail). A caller MUST compare it with the generation its
 *                stored ids came from — under a new UIDVALIDITY no stored
 *                "<uid>:<folder>" id matches anything, and a naive diff
 *                reads the whole folder as deleted
 *  - complete    false ⇒ the listing stopped short (page cap, or the id
 *                space changed while it was being read). A caller that
 *                treats "absent" as "left the folder" must not use it
 */
final class FolderListing
{
    /** @param array<string,true> $ids */
    public function __construct(
        public readonly array $ids,
        public readonly ?string $generation,
        public readonly bool $complete,
    ) {
    }

    public function count(): int
    {
        return count($this->ids);
    }

    public function has(string $providerId): bool
    {
        return isset($this->ids[$providerId]);
    }
}
