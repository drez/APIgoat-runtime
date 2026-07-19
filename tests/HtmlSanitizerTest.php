<?php
// Run: php tests/HtmlSanitizerTest.php   (from the runtime repo root)
//
// HtmlSanitizer::clean() is the authoritative write-side sanitizer for stored
// WYSIWYG HTML (called from the GoatCheese behavior's preSave). It is an
// ALLOWLIST DOM sanitizer: safe formatting survives; scripts, event handlers,
// dangerous URL schemes, style-based fetches and mutation-XSS vectors do not.
// These tests pin both halves of the contract.

require __DIR__ . '/../src/Utility/HtmlSanitizer.php';

use ApiGoat\Utility\HtmlSanitizer;

$fail = 0;

/** Output must NOT contain $needle (case-insensitive). */
function refuse(string $label, string $input, string $needle): void
{
    global $fail;
    $out = HtmlSanitizer::clean($input);
    if (stripos($out, $needle) === false) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label — '$needle' survived: $out\n";
        $fail++;
    }
}

/** Output MUST contain $needle (formatting/round-trip preserved). */
function keep(string $label, string $input, string $needle): void
{
    global $fail;
    $out = HtmlSanitizer::clean($input);
    if (stripos($out, $needle) !== false) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label — '$needle' missing: $out\n";
        $fail++;
    }
}

function same(string $label, $got, $want): void
{
    global $fail;
    if ($got === $want) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label — got " . var_export($got, true) . "\n";
        $fail++;
    }
}

echo "-- neutralizes --\n";
refuse('script block',            '<script>alert(1)</script><p>x</p>', 'alert');
refuse('inline onerror',          '<img src=x onerror="alert(1)">',    'onerror');
refuse('inline onclick',          '<p onclick="x()">a</p>',            'onclick');
refuse('javascript: href',        '<a href="javascript:alert(1)">x</a>', 'javascript');
refuse('vbscript: href',          '<a href="vbscript:msgbox">x</a>',   'vbscript');
refuse('tab-obfuscated js href',  '<a href="java&#09;script:alert(1)">x</a>', 'alert');
refuse('iframe',                  '<iframe src="//evil"></iframe>',    'iframe');
refuse('object',                  '<object data="evil"></object>',     'object');
refuse('embed',                   '<embed src="evil">',                'embed');
refuse('svg wrapper (mXSS)',      '<svg><script>alert(1)</script></svg>', 'alert');
refuse('math wrapper',            '<math><script>alert(1)</script></math>', 'alert');
refuse('form + input',            '<form action="x"><input name="y"></form>ok', 'input');
refuse('style block',             '<style>body{x:y}</style><p>z</p>',  '<style');
refuse('css url() fetch',         '<div style="background:url(javascript:alert(1))">x</div>', 'javascript');
refuse('css expression',          '<div style="width:expression(alert(1))">x</div>', 'expression');
refuse('data:text/html img',      '<img src="data:text/html,<script>alert(1)</script>">', 'script');
refuse('conditional comment',     '<p>a</p><!--[if IE]><script>bad()</script><![endif]-->', 'bad');
refuse('unknown-tag not kept as tag', '<foo onclick="x">bar</foo>', '<foo');

echo "-- preserves --\n";
keep('paragraph',        '<p>Hello world</p>',                    '<p>');
keep('strong',           '<p>a <strong>b</strong></p>',           '<strong>');
keep('em',               '<p>a <em>b</em></p>',                   '<em>');
keep('list',             '<ul><li>one</li><li>two</li></ul>',     '<li>');
keep('safe link',        '<a href="https://ok.com/p?x=1">x</a>',  'https://ok.com/p?x=1');
keep('relative link',    '<a href="/local/path">x</a>',           '/local/path');
keep('mailto link',      '<a href="mailto:a@b.com">x</a>',        'mailto:a@b.com');
keep('safe img',         '<img src="https://ok.com/a.png" alt="pic">', 'https://ok.com/a.png');
keep('data:image ok',    '<img src="data:image/png;base64,iVBORw0KGgo=">', 'data:image/png;base64');
keep('safe css',         '<div style="text-align:center;color:red">x</div>', 'text-align: center');
keep('table',            '<table><tr><td colspan="2">c</td></tr></table>', '<td colspan="2">');
keep('unknown unwrapped text', '<foo>keep this</foo>',            'keep this');
keep('target gets rel',  '<a href="https://x.com" target="_blank">x</a>', 'noopener');

echo "-- edges --\n";
same('empty string',   HtmlSanitizer::clean(''),      '');
same('whitespace',     HtmlSanitizer::clean("  \n "), '');
same('plain text kept', HtmlSanitizer::clean('just text'), 'just text');

echo $fail ? "\nFAILED ($fail)\n" : "\nOK\n";
exit($fail ? 1 : 0);
