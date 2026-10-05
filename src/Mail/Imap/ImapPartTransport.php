<?php

namespace ApiGoat\Mail\Imap;

/**
 * Optional transport capability behind {@see \ApiGoat\Mail\PartReader}: a
 * message's BODYSTRUCTURE and one section's bytes, both with PEEK semantics
 * (\Seen untouched). Separate from ImapTransport so existing fakes and
 * bindings keep compiling.
 */
interface ImapPartTransport
{
    /** The raw untagged FETCH text holding BODYSTRUCTURE (literals kept), or null when $uid is not in $folder. */
    public function bodyStructure(string $folder, int $uid): ?string;

    /**
     * BODY.PEEK[$section]<0.$maxOctets>: at most $maxOctets ENCODED bytes of the
     * section, handed to $sink as they arrive.
     *
     * @param callable(string):void $sink may throw: the transport drains the rest and rethrows
     * @return int|null octets delivered, null when the server sent no such item
     */
    public function streamSection(string $folder, int $uid, string $section, int $maxOctets, callable $sink): ?int;
}
