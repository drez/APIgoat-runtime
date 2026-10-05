<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\MailHtml;
use PHPUnit\Framework\TestCase;

/**
 * Stored bodies are UTF-8, but keep the sender's own charset declaration. libxml honoured a
 * `charset=Windows-1252` meta over our `<?xml encoding="UTF-8">` and turned "é" into "Ã©" (2026-10-05).
 */
final class MailHtmlCharsetTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function declarations(): array
    {
        return [
            'http-equiv windows-1252' => ['<html><head><meta http-equiv="Content-Type" content="text/html; charset=Windows-1252"></head><body><p>élèves plutôt qu’un</p></body></html>'],
            'upper-case unquoted'     => ['<META HTTP-EQUIV=Content-Type CONTENT="text/html; charset=iso-8859-1"><p>élèves plutôt qu’un</p>'],
            'meta charset'            => ['<meta charset="iso-8859-1"><p>élèves plutôt qu’un</p>'],
            'no declaration'          => ['<p>élèves plutôt qu’un</p>'],
        ];
    }

    /** @dataProvider declarations */
    public function test_utf8_text_survives_any_charset_declaration(string $html): void
    {
        $out = MailHtml::defuse($html);
        $this->assertStringContainsString('élèves plutôt qu’un', $out);
        $this->assertStringNotContainsString('Ã', $out);
    }

    public function test_only_charset_metas_are_dropped(): void
    {
        $in = '<meta name="viewport" content="width=device-width"><meta http-equiv="content-type" content="text/html; charset=windows-1252"><meta charset=latin1><p>x</p>';
        $out = MailHtml::dropCharsetDeclarations($in);
        $this->assertStringContainsString('name="viewport"', $out);
        $this->assertStringNotContainsStringIgnoringCase('charset', $out);
    }
}
