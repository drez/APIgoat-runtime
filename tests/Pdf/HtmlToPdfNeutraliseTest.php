<?php
// The SSRF / local-file guard existed on the dompdf path only, while
// wkhtmltopdf and Chrome are tried first. The document is rendered from a
// local temp file, so <iframe src="file:///etc/passwd"> printed that file into
// the PDF. HtmlToPdf::neutralise() applies the dompdf rule to the HTML itself.
namespace ApiGoat\Tests\Pdf;

use ApiGoat\Pdf\HtmlToPdf;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Pdf/HtmlToPdf.php';

final class HtmlToPdfNeutraliseTest extends TestCase
{
    /** Stand-in for assertPublicUrl (no DNS in a unit test): only *.public.test is public. */
    private static function rule(): callable
    {
        return static fn (string $url): array => [str_ends_with((string) parse_url($url, PHP_URL_HOST), 'public.test'), ''];
    }

    private static function clean(string $html): string
    {
        return HtmlToPdf::neutralise($html, self::rule());
    }

    public function test_active_and_embedding_elements_are_removed(): void
    {
        $out = self::clean('<p>a</p><script>x()</script><SCRIPT src="https://cdn.public.test/x.js"></SCRIPT>'
            . '<iframe src="file:///etc/passwd">in</iframe><object data="file:///etc/passwd"></object><embed src="file:///etc/passwd">'
            . '<frameset><frame src="file:///etc/passwd"></frameset><base href="file:///"><meta http-equiv="refresh" content="0;url=file:///etc/passwd">'
            . '<meta charset="utf-8"><p onclick="x()" onload=\'y()\'>b</p>');
        foreach (['<script', '<iframe', '<object', '<embed', '<frame', '<base', 'refresh', 'onclick', 'onload', '/etc/passwd'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $out, $bad);
        }
        $this->assertStringContainsString('<meta charset="utf-8">', $out);
        $this->assertStringContainsString('<p>a</p>', $out);
    }

    public function test_only_fragment_data_and_public_http_urls_survive(): void
    {
        $keep = [
            '<img src="data:image/png;base64,AAAA">',
            '<img src="https://img.public.test/logo.png">',
            '<img alt=">" src=\'http://img.public.test/a.png\'>',
            '<a href="file:///etc/passwd">a link fetches nothing</a>',
            '<a href="/relative">x</a>',
            '<use xlink:href="#icon">',
            '<img srcset="https://img.public.test/a.png 1x, https://img.public.test/b.png 2x">',
        ];
        foreach ($keep as $html) {
            $this->assertSame($html, self::clean($html));
        }

        $blocked = [
            '<img src="file:///etc/passwd">',
            '<img src="FILE:///etc/passwd">',
            '<img src="&#102;ile:///etc/passwd">',
            "<img src=\"fi&#9;le:///etc/passwd\">",
            '<img src=file:///etc/passwd>',
            '<img src="/etc/passwd">',
            '<img src="../../.env">',
            '<img src="//169.254.169.254/latest/meta-data/">',
            '<img src="http://169.254.169.254/latest/meta-data/">',
            '<img src="http://localhost/admin">',
            '<img src="ftp://img.public.test/a.png">',
            '<img src="javascript:alert(1)">',
            '<img srcset="https://img.public.test/a.png 1x, file:///etc/passwd 2x">',
            '<video poster="file:///etc/passwd">',
            '<body background="http://10.0.0.5/x.png">',
            '<link rel="stylesheet" href="file:///etc/passwd">',
            '<image href="file:///etc/passwd">',
            '<image xlink:href="http://127.0.0.1/x">',
        ];
        foreach ($blocked as $html) {
            $out = self::clean($html);
            $this->assertStringContainsString('about:blank', $out, $html);
            foreach (['passwd', '.env', '169.254', 'localhost', '10.0.0.5', '127.0.0.1', 'javascript', 'ftp:'] as $bad) {
                $this->assertStringNotContainsString($bad, $out, $html);
            }
        }
    }

    public function test_css_urls_and_imports_follow_the_same_rule(): void
    {
        $out = self::clean('<style>@import "file:///etc/passwd"; @import url(http://10.0.0.5/a.css);'
            . '.a{background:url("file:///etc/passwd")} .b{background:URL( /etc/shadow )} .c{background:url(\\66ile:///etc/passwd)}'
            . '.d{background:url(https://img.public.test/bg.png)} .e{fill:url(#grad)} .f{background:url(data:image/png;base64,AAAA)}'
            . '.g{background:image-set("file:///etc/passwd" 1x)}</style><p style="background:url(\'http://localhost/x\')">t</p>');
        foreach (['passwd', 'shadow', '10.0.0.5', 'localhost'] as $bad) {
            $this->assertStringNotContainsString($bad, str_replace('blocked-image-set("file:///etc/passwd" 1x)', '', $out), $bad);
        }
        $this->assertStringContainsString('blocked-image-set(', $out);
        $this->assertStringContainsString('url(https://img.public.test/bg.png)', $out);
        $this->assertStringContainsString('url(#grad)', $out);
        $this->assertStringContainsString('url(data:image/png;base64,AAAA)', $out);
    }

    public function test_encoded_css_and_malformed_tags_are_refused(): void
    {
        $refused = [
            '<p style="background:url&#40;http://169.254.169.254/x&#41;">a</p>',
            '<p style="background:u\\72 l(http://169.254.169.254/x)">a</p>',
            '<style>p{background:u\\rl(http://169.254.169.254/x)}</style>',
            '<style>@\\69mport "http://169.254.169.254/x";</style>',
            '<svg><rect fill="&#117;rl(http://169.254.169.254/)"/></svg>',
            "<iframe src=http://169.254.169.254/latest/meta-data/ x=a'b>",
            '<img alt=a"b src="http://169.254.169.254/">',
            "<img src=http://169.254.169.254/ a=\"<\" b=c'>",
            "<meta http-equiv=refresh content=0;url=file:///etc/passwd a=b'c>",
        ];
        foreach ($refused as $html) {
            try {
                self::clean($html);
                $this->fail('not refused: ' . $html);
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith('PDF:', $e->getMessage(), $html);
            }
        }
    }

    public function test_every_meta_but_charset_is_removed_and_imports_without_space_are_checked(): void
    {
        foreach ([
            '<meta content="0;url=file:///etc/passwd" x=">" http-equiv=refresh>',
            '<meta http-equiv="&#114;efresh" content="0;url=http://169.254.169.254/">',
        ] as $html) {
            $this->assertSame('', self::clean($html), $html);
        }
        $this->assertSame('<meta charset="utf-8">', self::clean('<meta charset="utf-8">'));
        foreach (['<style>@import"http://169.254.169.254/x";</style>', '<style>@import/**/"file:///etc/passwd";</style>'] as $html) {
            $this->assertStringContainsString('about:blank', self::clean($html), $html);
        }
    }

    public function test_legitimate_css_escapes_and_entities_still_pass(): void
    {
        $html = '<style>p:before{content:"\\201C"} a{color:#f00;font:12px Arial}</style>'
            . '<p style="color:red;background:url(\'https://img.public.test/a.png?a=1&amp;b=2\')">Tom &amp; Jerry, 3<4. C:\\Temp</p>';
        $this->assertSame($html, self::clean($html));
    }

    public function test_a_large_data_uri_document_passes_through_unchanged(): void
    {
        $big = base64_encode(random_bytes(2 * 1024 * 1024));
        $html = '<html><head><style>@font-face{font-family:X;src:url(data:font/ttf;base64,' . $big . ')}</style></head>'
            . '<body><img src="data:image/png;base64,' . $big . '"><p>x</p></body></html>';
        $this->assertSame($html, self::clean($html));
    }

    public function test_the_default_rule_is_the_dompdf_ssrf_rule(): void
    {
        $this->assertStringContainsString('about:blank', HtmlToPdf::neutralise('<img src="http://127.0.0.1/x.png">'));
        $this->assertStringContainsString('about:blank', HtmlToPdf::neutralise('<img src="http://[::1]/x.png">'));
    }

    public function test_csp_is_the_first_thing_in_head(): void
    {
        $out = HtmlToPdf::withCsp('<html><head lang="fr"><title>t</title></head><body></body></html>');
        $this->assertStringContainsString('<head lang="fr"><meta http-equiv="Content-Security-Policy"', $out);
        $this->assertStringStartsWith('<meta http-equiv="Content-Security-Policy"', HtmlToPdf::withCsp('<p>no head</p>'));
        $this->assertStringContainsString("default-src 'none'", HtmlToPdf::CSP);
        $this->assertStringNotContainsString('file:', HtmlToPdf::CSP);
    }

    public function test_project_fonts_are_inlined_only_from_public_fonts(): void
    {
        if (!\defined('_BASE_DIR')) {
            $base = sys_get_temp_dir() . '/gc-pdfn-' . getmypid() . '/';
            @mkdir($base . 'public/fonts/inter', 0777, true);
            file_put_contents($base . 'public/fonts/inter/Inter-Regular.ttf', 'FONTBYTES');
            file_put_contents($base . 'secret.ttf', 'SECRET');
            \define('_BASE_DIR', $base);
        } elseif (!is_file(rtrim((string) \_BASE_DIR, '/') . '/public/fonts/inter/Inter-Regular.ttf')) {
            $this->markTestSkipped('_BASE_DIR already defined by another test');
        }
        $out = HtmlToPdf::inlineProjectFonts("src: url('https://x.test/public/fonts/inter/Inter-Regular.ttf') format('truetype')");
        $this->assertStringContainsString('url(data:font/ttf;base64,' . base64_encode('FONTBYTES') . ')', $out);
        $escape = "src: url('https://x.test/public/fonts/../../secret.ttf')";
        $this->assertSame($escape, HtmlToPdf::inlineProjectFonts($escape));
    }
}
