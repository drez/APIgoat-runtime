<?php

use ApiGoat\Mail\MailHtml;
use PHPUnit\Framework\TestCase;

/** MailHtml::defuse — an email keeps its look, loses everything active. */
final class MailHtmlTest extends TestCase
{
    public function test_styles_and_layout_attributes_survive(): void
    {
        $out = MailHtml::defuse('<html><head><style>.x{color:red}</style></head><body bgcolor="#eee"><table cellpadding="4" align="center"><tr><td style="font-family:Arial;background-color:#fff">Hi</td></tr></table></body></html>');
        $this->assertStringContainsString('<style>.x{color:red}</style>', $out);
        $this->assertStringContainsString('<body bgcolor="#eee">', $out);
        $this->assertStringContainsString('cellpadding="4" align="center"', $out);
        $this->assertStringContainsString('style="font-family:Arial;background-color:#fff"', $out);
        $this->assertStringContainsString('<meta http-equiv="Content-Security-Policy" content="' . MailHtml::CSP_BLOCKED . '">', $out);
        $this->assertStringContainsString('<base target="_blank">', $out);
    }

    public function test_active_content_and_handlers_are_removed(): void
    {
        $out = MailHtml::defuse('<p onclick="x()" onmouseover="y()">a<script>alert(1)</script><iframe src="https://e.x"></iframe><form action="https://e.x"><input name="q"><button>go</button>keep</form><meta http-equiv="refresh" content="0;url=https://e.x"><link rel="stylesheet" href="https://e.x/a.css"><object data="x"></object><svg onload="z()"></svg></p>');
        foreach (['<script', '<iframe', '<form', '<input', '<button', '<meta http-equiv="refresh"', '<link', '<object', '<svg', 'onclick', 'onmouseover', 'onload'] as $bad) {
            $this->assertStringNotContainsString($bad, $out, $bad);
        }
        $this->assertStringContainsString('akeep', str_replace(["\n"], '', strip_tags($out)), 'the form body text is kept');
    }

    public function test_dangerous_url_schemes_are_dropped_and_links_open_in_a_new_tab(): void
    {
        $out = MailHtml::defuse('<a href="javascript:alert(1)">j</a><a href="  JAVA&#10;SCRIPT:alert(1)">k</a><a href="https://ok.example/p">ok</a><a href="mailto:a@b.c">m</a><img src="data:text/html,x"><img src="data:image/png;base64,AAAA">');
        $this->assertStringNotContainsString('javascript', strtolower($out));
        $this->assertStringContainsString('<a href="https://ok.example/p" target="_blank" rel="noopener noreferrer">ok</a>', $out);
        $this->assertStringContainsString('href="mailto:a@b.c"', $out);
        $this->assertStringNotContainsString('data:text/html', $out);
        $this->assertStringContainsString('src="data:image/png;base64,AAAA"', $out, 'inline data images stay');
    }

    public function test_remote_images_are_blocked_behind_a_placeholder_and_restorable(): void
    {
        $blocked = MailHtml::defuse('<img src="https://t.example/px.gif" width="1" height="1">');
        $this->assertStringContainsString('<img width="1" height="1" src="' . MailHtml::IMG_PLACEHOLDER . '" data-gm-src="https://t.example/px.gif">', $blocked);
        $this->assertStringContainsString(MailHtml::CSP_BLOCKED, $blocked);

        $shown = MailHtml::withImages($blocked);
        $this->assertStringContainsString('<img width="1" height="1" src="https://t.example/px.gif">', $shown);
        $this->assertStringContainsString(MailHtml::CSP_IMAGES, $shown);
    }

    public function test_css_escape_hatches_are_neutralised(): void
    {
        $out = MailHtml::defuse('<style>@import url(https://e.x/a.css); .a{width:expression(alert(1));behavior:url(x.htc);background:url(javascript:1)} .b{background:url(https://ok/x.png)}</style><p style="-moz-binding:url(x);color:red">p</p>');
        $this->assertStringNotContainsString('@import', $out);
        $this->assertStringNotContainsString('expression(', $out);
        $this->assertStringNotContainsString('behavior:', $out);
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsString('-moz-binding', $out);
        // an http(s) url() survives the scheme filter — parked while images are blocked, as written once shown
        $this->assertStringContainsString('url(' . MailHtml::BLOCKED_PREFIX . 'https://ok/x.png)', $out);
        $this->assertStringContainsString('url(https://ok/x.png)', MailHtml::withImages($out));
        $this->assertStringContainsString('style="color:red"', $out);
    }

    public function test_a_fragment_and_utf8_are_handled(): void
    {
        $out = MailHtml::defuse('<p>Café — “quotes” 日本</p>');
        $this->assertStringContainsString('Café — “quotes” 日本', $out);
        $this->assertStringContainsString('<body><p>', $out);
        $this->assertSame('', MailHtml::defuse('  '));
    }

    public function test_blocked_images_cover_every_remote_reference_not_just_img_src(): void
    {
        $in = '<style>.h{background:url("https://t.example/bg.gif")} .k{background:url(data:image/png;base64,AAAA)}</style>'
            . '<img src="https://t.example/p.gif" srcset="https://t.example/q.gif 1x"><img srcset="https://t.example/only.gif 2x">'
            . '<image src="https://t.example/i.gif"><bgsound src="https://t.example/s.wav">'
            . '<table background="https://t.example/tb.gif"><tr><td style="background:url(//t.example/x.gif)" poster="https://t.example/po.gif">a</td></tr></table>';
        $out = MailHtml::defuse($in);
        // nothing left that a browser would fetch: every remaining mention is parked
        $parked = preg_replace('#(?:data-gm-src="|' . preg_quote(MailHtml::BLOCKED_PREFIX, '#') . ')(?:https:)?//t\.example#', '', $out);
        $this->assertStringNotContainsString('t.example', $parked, $out);
        foreach (['srcset', 'poster', '<bgsound', 'only.gif', 'q.gif', 's.wav'] as $gone) {
            $this->assertStringNotContainsString($gone, $out, $gone);
        }
        $this->assertStringContainsString('background="' . MailHtml::BLOCKED_PREFIX . 'https://t.example/tb.gif"', $out);
        $this->assertStringContainsString('url(data:image/png;base64,AAAA)', $out);

        // "Show images" brings the img, the <image>, the attribute and both CSS backgrounds back
        $shown = MailHtml::withImages($out);
        $this->assertStringNotContainsString(MailHtml::BLOCKED_PREFIX, $shown);
        foreach (['src="https://t.example/p.gif"', 'src="https://t.example/i.gif"', 'background="https://t.example/tb.gif"', 'url("https://t.example/bg.gif")', 'url(//t.example/x.gif)'] as $back) {
            $this->assertStringContainsString($back, $shown, $back);
        }
        // and with images on from the start nothing is parked
        $this->assertStringNotContainsString(MailHtml::BLOCKED_PREFIX, MailHtml::defuse($in, true));
    }

    public function test_a_sender_cannot_pre_park_a_url(): void
    {
        $out = MailHtml::defuse('<a href="javax-gm-blocked:script:alert(1)">x</a><p data-gm-src="javascript:1" title="X-GM-BLOCKED:">t</p>'
            . '<td style="background:url(x-gm-blocked:javascript:1)">c</td><style>.a{background:url(&quot;)}</style>');
        $this->assertStringNotContainsStringIgnoringCase('javascript', MailHtml::withImages($out));
        $this->assertStringNotContainsString('data-gm-src', $out);
    }

    public function test_css_cleaning_cannot_reassemble_a_closing_style_tag(): void
    {
        foreach ([
            '<style>p{}<@import;/style><img src=x onerror=alert(1)></style>',
            '<style>p{}<@imp@import;ort;/style><img src=x onerror=alert(1)></style>',
            '<style>p{}<-moz-binding:x;/style><img src=x onerror=alert(1)></style>',
            '<style>p{}<x-gm-blocked:/style><img src=x onerror=alert(1)></style>',
        ] as $in) {
            foreach ([false, true] as $images) {
                $out = MailHtml::defuse($in, $images);
                $this->assertStringNotContainsString('<img', $out, $in);
                $this->assertSame(1, substr_count($out, '</style>') - substr_count($out, '<style>') + 1, $in);
                $this->assertStringNotContainsString('<img', MailHtml::withImages($out), $in);
            }
        }
    }

    public function test_css_escapes_cannot_hide_url_import_or_behavior(): void
    {
        $out = MailHtml::defuse('<style>p{background:u\\72 l(https://t.example/px)} q{background:\\75 rl(javascript:alert(1))}'
            . ' @\\69mport "https://e.x/a.css"; .\\31 0px{color:red} a:before{content:"\\201C"}</style>'
            . '<p style="b\\65havior:url(x.htc);color:red">x</p>');
        $this->assertStringContainsString('url(' . MailHtml::BLOCKED_PREFIX . 'https://t.example/px)', $out, 'escaped url() is parked like a plain one');
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsStringIgnoringCase('mport', $out);
        $this->assertStringNotContainsString('havior', $out);
        $this->assertStringContainsString('.\\31 0px{color:red}', $out, 'a digit-escaped class keeps its escape');
        $this->assertStringContainsString('content:"\\201C"', $out);
        $this->assertStringContainsString('style="color:red"', $out);
    }

    public function test_an_escaped_backslash_is_not_the_start_of_a_new_escape(): void
    {
        // "u\\72 l(" is u + a literal backslash + "72 l(" — inert. Decoding the
        // second backslash alone produced "u\rl(", which browsers read as url(.
        foreach ([false, true] as $images) {
            $out = MailHtml::defuse('<style>p{background:u\\\\72 l(http://evil.example/px.png)} @\\\\69mport "http://evil.example/x.css";</style>', $images);
            $this->assertStringNotContainsString('u\\rl(', $out);
            $this->assertStringNotContainsString('@\\import', $out);
            $this->assertStringContainsString('u\\\\72 l(', $out, 'the escaped backslash is kept as written');
        }
    }

    public function test_string_urls_in_image_functions_follow_the_url_policy(): void
    {
        // image-set("…" 1x) takes a plain string as an image URL (security re-check of 59d0d88).
        $in = '<style>.a{background-image:image-set("http://t.example/px.gif" 1x, url(https://t.example/p2.gif) 2x)}'
            . ' .b{background-image:-webkit-image-set(\'https://t.example/w.gif\' 1x)}'
            . ' .c{background-image:image-set("javascript:alert(1)" 1x, "data:image/png;base64,AAAA" 2x)}</style>';
        $out = MailHtml::defuse($in, false);
        $this->assertStringContainsString('image-set("' . MailHtml::BLOCKED_PREFIX . 'http://t.example/px.gif" 1x', $out);
        $this->assertStringContainsString("-webkit-image-set('" . MailHtml::BLOCKED_PREFIX . "https://t.example/w.gif' 1x)", $out);
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringContainsString('"data:image/png;base64,AAAA" 2x', $out);
        $this->assertStringContainsString('image-set("http://t.example/px.gif" 1x', MailHtml::withImages($out), 'Show images restores it');
        $this->assertStringContainsString('image-set("http://t.example/px.gif" 1x', MailHtml::defuse($in, true), 'images on: kept as written');
        $this->assertStringContainsString('content:"x"', MailHtml::defuse('<style>a:before{content:"x"}</style>', false), 'plain strings untouched');
    }
}
