<?php

namespace ApiGoat\Mail;

/**
 * Streaming Content-Transfer-Encoding decoder (base64, quoted-printable,
 * identity for 7bit/8bit/binary and anything unknown). Feed it the ENCODED
 * bytes in chunks of any size; it hands decoded bytes to $sink as they
 * complete and throws PartTooLarge the moment the decoded total passes
 * $max — the caller never holds more than one chunk plus a line.
 */
final class PartDecoder
{
    private string $buf = '';
    private int $out = 0;
    private string $mode;

    /** @param callable(string):void $sink */
    public function __construct(string $encoding, private int $max, private $sink)
    {
        $e = strtolower(trim($encoding));
        $this->mode = $e === 'base64' ? 'b64' : ($e === 'quoted-printable' ? 'qp' : 'raw');
    }

    public function write(string $chunk): void
    {
        if ($this->mode === 'raw') {
            $this->emit($chunk);
            return;
        }
        if ($this->mode === 'b64') {
            $this->buf .= (string) preg_replace('/[^A-Za-z0-9+\/]/', '', $chunk);
            $whole = strlen($this->buf) - strlen($this->buf) % 4;
            if ($whole > 0) {
                $this->emit((string) base64_decode(substr($this->buf, 0, $whole)));
                $this->buf = substr($this->buf, $whole);
            }
            return;
        }
        $this->buf .= $chunk;
        $nl = strrpos($this->buf, "\n");
        if ($nl !== false) {
            $this->emit(quoted_printable_decode(substr($this->buf, 0, $nl + 1)));
            $this->buf = substr($this->buf, $nl + 1);
        } elseif (strlen($this->buf) > 65536) {
            // A QP line is at most 76 chars: a 64 KB line is not QP. Decode what we have.
            $this->emit(quoted_printable_decode($this->buf));
            $this->buf = '';
        }
    }

    /** @return int the decoded total */
    public function finish(): int
    {
        if ($this->buf !== '') {
            if ($this->mode === 'b64') {
                $this->emit((string) base64_decode($this->buf . str_repeat('=', (4 - strlen($this->buf) % 4) % 4)));
            } else {
                $this->emit(quoted_printable_decode($this->buf));
            }
            $this->buf = '';
        }
        return $this->out;
    }

    private function emit(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $this->out += strlen($bytes);
        if ($this->out > $this->max) {
            throw new PartTooLarge($this->max);
        }
        ($this->sink)($bytes);
    }
}
