<?php

namespace ApiGoat\Mail;

/**
 * A connector that can list a message's MIME parts and stream ONE of them
 * (IMAP BODYSTRUCTURE + BODY.PEEK[<section>]) without downloading the
 * message. \Seen is never touched.
 */
interface PartReader
{
    /**
     * @return list<array<string,mixed>> the leaves, {@see BodyStructure} shape
     * @throws \ApiGoat\Sync\Exceptions\ValidationRejected code 404: the message is not there
     */
    public function fetchStructure(string $providerId): array;

    /**
     * Stream the DECODED bytes of $section into $sink.
     *
     * @param string $encoding the part's Content-Transfer-Encoding (from fetchStructure())
     * @param int    $maxBytes decoded cap: PartTooLarge as soon as it is passed; the server is
     *                         asked for a bounded prefix only, so an over-cap part is never read whole
     * @param callable(string):void $sink
     * @return int decoded bytes delivered
     * @throws PartTooLarge
     * @throws \ApiGoat\Sync\Exceptions\ValidationRejected code 404: no such message or section
     */
    public function fetchPart(string $providerId, string $section, string $encoding, int $maxBytes, callable $sink): int;
}
