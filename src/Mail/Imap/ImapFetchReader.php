<?php

namespace ApiGoat\Mail\Imap;

use ApiGoat\Sync\Exceptions\TransientError;

/**
 * Reads the response to ONE tagged UID FETCH straight off the IMAP stream,
 * for the two reads webklex's own fetch() cannot do safely:
 *
 *  - collect(): the untagged FETCH text with every literal kept as
 *    "{n}\r\n<bytes>" (BODYSTRUCTURE carries filenames as literals; webklex's
 *    tokenizer mis-splits a quoted string followed by ')'), bounded;
 *  - streamLiteral(): a BODY[<section>] literal handed to a sink in 64 KB
 *    chunks as it arrives — never held whole (webklex reads a literal into
 *    memory one byte at a time and keeps a copy of every line).
 *
 * Pure stream code (tested on php://memory). Both read to the tagged status
 * line, so the connection is left in sync whatever happens; a sink that
 * throws has the rest of the literal drained (bounded by what was asked —
 * a partial fetch) before its exception is rethrown.
 */
final class ImapFetchReader
{
    private const CHUNK = 65536;
    private const MAX_LINE = 1048576;

    /**
     * @return string the untagged FETCH responses, concatenated ('' when the uid is not there)
     * @throws TransientError the connection broke, or the response passed $maxBytes
     * @throws \RuntimeException NO / BAD
     */
    public static function collect($stream, string $tag, int $maxBytes): string
    {
        $out = '';
        while (true) {
            $line = self::line($stream);
            if (self::isTagged($line, $tag)) {
                self::assertOk($line);
                return $out;
            }
            $resp = $line;
            while (preg_match('/\{(\d{1,10})\}\r?\n$/', $resp, $m)) {
                $n = (int) $m[1];
                if (strlen($resp) + $n > $maxBytes) {
                    throw new TransientError('IMAP response larger than ' . $maxBytes . ' bytes');
                }
                self::exact($stream, $n, static function (string $c) use (&$resp): void { $resp .= $c; });
                $resp .= self::line($stream);
            }
            if (preg_match('/^\* \d+ FETCH /i', $resp)) {
                if (strlen($out) + strlen($resp) > $maxBytes) {
                    throw new TransientError('IMAP response larger than ' . $maxBytes . ' bytes');
                }
                $out .= $resp;
            }
        }
    }

    /**
     * @param callable(string):void $sink
     * @return int|null bytes of the BODY[...] item handed to $sink; null when the server sent none (no such uid / NIL)
     * @throws TransientError the connection broke
     * @throws \RuntimeException NO / BAD
     */
    public static function streamLiteral($stream, string $tag, callable $sink): ?int
    {
        $got   = null;
        $error = null;
        while (true) {
            $line = self::line($stream);
            if (self::isTagged($line, $tag)) {
                if ($error !== null) {
                    throw $error;
                }
                self::assertOk($line);
                return $got;
            }
            // Every literal on this response line (normally one: the BODY item).
            while (true) {
                if (preg_match('/BODY\[[^\]]*\](?:<\d+>)?\s+\{(\d{1,10})\}\r?\n$/i', $line, $m) && $got === null) {
                    $n   = (int) $m[1];
                    $got = $n;
                    self::exact($stream, $n, static function (string $c) use ($sink, &$error): void {
                        if ($error === null) {
                            try {
                                $sink($c);
                            } catch (\Throwable $e) {
                                $error = $e;   // keep draining: the connection must stay in sync
                            }
                        }
                    });
                    $line = self::line($stream);
                    continue;
                }
                if (preg_match('/\{(\d{1,10})\}\r?\n$/', $line, $m)) {
                    self::exact($stream, (int) $m[1], static function (string $c): void {});
                    $line = self::line($stream);
                    continue;
                }
                if ($got === null && preg_match('/BODY\[[^\]]*\](?:<\d+>)?\s+"((?:[^"\\\\]|\\\\.)*)"/i', $line, $m)) {
                    $bytes = stripcslashes($m[1]);
                    $got   = strlen($bytes);
                    if ($bytes !== '' && $error === null) {
                        try {
                            $sink($bytes);
                        } catch (\Throwable $e) {
                            $error = $e;
                        }
                    }
                }
                break;
            }
        }
    }

    private static function isTagged(string $line, string $tag): bool
    {
        return str_starts_with($line, $tag . ' ');
    }

    private static function assertOk(string $line): void
    {
        $status = strtoupper((string) (explode(' ', trim($line))[1] ?? ''));
        if ($status !== 'OK') {
            throw new \RuntimeException('IMAP FETCH refused: ' . trim(substr($line, 0, 300)));
        }
    }

    private static function line($stream): string
    {
        $line = '';
        while (true) {
            $c = fgets($stream, 8192);
            if ($c === false) {
                throw new TransientError(self::broken($stream));
            }
            $line .= $c;
            if (str_ends_with($line, "\n")) {
                return $line;
            }
            if (strlen($line) > self::MAX_LINE) {
                throw new TransientError('IMAP response line too long');
            }
        }
    }

    /** @param callable(string):void $each */
    private static function exact($stream, int $n, callable $each): void
    {
        while ($n > 0) {
            $c = fread($stream, min(self::CHUNK, $n));
            if ($c === false || $c === '') {
                if (feof($stream) || (stream_get_meta_data($stream)['timed_out'] ?? false)) {
                    throw new TransientError(self::broken($stream));
                }
                continue;
            }
            $n -= strlen($c);
            $each($c);
        }
    }

    private static function broken($stream): string
    {
        return (stream_get_meta_data($stream)['timed_out'] ?? false) ? 'IMAP read timed out' : 'IMAP connection closed while reading';
    }
}
