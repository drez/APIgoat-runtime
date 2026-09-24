<?php
// Run: php tests/Document/TextVariablesTest.php
// {Token} values are data: escaped everywhere, and scheme-checked when they
// land in an href/src (an escaped "javascript:alert(1)" is still a script URL).
require __DIR__ . '/../../src/Utility/HtmlSanitizer.php';
require __DIR__ . '/../../src/Document/TextVariables.php';

use ApiGoat\Document\TextVariables;

$fail = 0;
function same(string $label, string $got, string $want): void
{
    if ($got === $want) { echo "  ok   $label\n"; } else { echo "  FAIL $label\n    got:  $got\n    want: $want\n"; $GLOBALS['fail']++; }
}

$vals = ['Website' => 'javascript:alert(1)', 'Logo' => 'data:text/html,<script>alert(1)</script>', 'A' => 'java',
         'B' => 'script:alert(1)', 'Site' => 'https://ex.test/?a=1&b=2', 'Name' => 'Tom & "Jerry" {Site}',
         'Path' => 'x y', 'Img' => 'https://ex.test/logo.png'];
same('javascript: href', TextVariables::substitute('<a href="{Website}">w</a>', $vals), '<a href="#">w</a>');
same('entity-free js via 2 tokens', TextVariables::substitute("<a href='{A}{B}'>w</a>", $vals), "<a href='#'>w</a>");
same('unquoted href', TextVariables::substitute('<a href={Website}>w</a>', $vals), '<a href="#">w</a>');
same('data:text src', TextVariables::substitute('<img src="{Logo}">', $vals), '<img src="">');
same('https href kept', TextVariables::substitute('<a href="{Site}">{Name}</a>', $vals),
    '<a href="https://ex.test/?a=1&amp;b=2">Tom &amp; &quot;Jerry&quot; {Site}</a>');
same('https src kept', TextVariables::substitute('<img src="{Img}" alt="{Name}">', $vals),
    '<img src="https://ex.test/logo.png" alt="Tom &amp; &quot;Jerry&quot; {Site}">');
same('value braces not re-substituted in href', TextVariables::substitute('<a href="https://ex.test/{Name}">x</a>', $vals),
    '<a href="https://ex.test/Tom &amp; &quot;Jerry&quot; &#123;Site&#125;">x</a>');
same('unknown token kept', TextVariables::substitute('<a href="{Nope}">{Nope}</a>', $vals), '<a href="{Nope}">{Nope}</a>');
same('text only', TextVariables::substitute('<p>{website}</p>', $vals), '<p>javascript:alert(1)</p>');
same('data-href untouched by the url check', TextVariables::substitute('<p data-href="{Website}">x</p>', $vals),
    '<p data-href="javascript:alert(1)">x</p>');

// Every URL-bearing attribute gets the scheme check, not just href/src.
same('xlink:href', TextVariables::substitute('<svg><a xlink:href="{Website}">x</a></svg>', $vals), '<svg><a xlink:href="#">x</a></svg>');
same('formaction', TextVariables::substitute('<button formaction="{Website}">x</button>', $vals), '<button formaction="#">x</button>');
same('poster', TextVariables::substitute('<video poster="{Website}"></video>', $vals), '<video poster=""></video>');
same('background', TextVariables::substitute('<td background="{Logo}">x</td>', $vals), '<td background="">x</td>');
same('srcset with a bad candidate', TextVariables::substitute('<img srcset="{Img} 1x, {Website} 2x">', $vals), '<img srcset="">');
same('srcset all safe', TextVariables::substitute('<img srcset="{Img} 1x, {Img} 2x">', $vals),
    '<img srcset="https://ex.test/logo.png 1x, https://ex.test/logo.png 2x">');

echo $fail ? "\nFAILED ($fail)\n" : "\nOK\n";
exit($fail ? 1 : 0);
