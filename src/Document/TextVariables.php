<?php

namespace ApiGoat\Document;

use ApiGoat\Utility\HtmlSanitizer;

/**
 * {Variable} substitution inside document section text (inclusions,
 * exclusions, notes, …). Tokens are `{Detail name}` and resolve against a
 * name => value map — typically a document's detail rows — matched
 * case/whitespace-insensitively so `{licence rbq}` finds "Licence RBQ".
 *
 * The section HTML is trusted (admin-authored WYSIWYG) but the substituted
 * VALUES are data: they are HTML-escaped on the way in. Unknown tokens are
 * left verbatim so an unresolved variable stays visible on the proof instead
 * of silently vanishing.
 */
final class TextVariables
{
    /**
     * @param string $html trusted section HTML containing {Token} markers
     * @param array<string,string> $values detail name => value
     */
    public static function substitute(string $html, array $values): string
    {
        if ($html === '' || $values === [] || !str_contains($html, '{')) {
            return $html;
        }
        $norm = [];
        foreach ($values as $name => $value) {
            $key = mb_strtolower(trim((string) $name));
            if ($key !== '' && !isset($norm[$key])) {
                $norm[$key] = (string) $value;
            }
        }
        $sub = static function (string $text, bool $inAttr) use ($norm): string {
            return (string) preg_replace_callback(
                '/\{([^{}]{1,150})\}/u',
                static function (array $m) use ($norm, $inAttr): string {
                    $key = mb_strtolower(trim($m[1]));
                    if (!array_key_exists($key, $norm)) {
                        return $m[0];
                    }
                    $v = htmlspecialchars($norm[$key], ENT_QUOTES, 'UTF-8');
                    // Braces as entities so the text pass below cannot re-substitute them.
                    return $inAttr ? str_replace(['{', '}'], ['&#123;', '&#125;'], $v) : $v;
                },
                $text
            );
        };
        // SECURITY: escaping is not enough inside an href/src — a value such as
        // "javascript:alert(1)" for href="{Website}" is still a script URL. Tokens
        // in those attributes are resolved first and the resulting URL must pass
        // the same scheme allowlist as HtmlSanitizer, else it becomes '#' / ''.
        // Every URL-bearing attribute, not just href/src: xlink:href, srcset,
        // poster, formaction, action and background take a URL too.
        $html = (string) preg_replace_callback(
            '/(\s(href|xlink:href|src|srcset|poster|formaction|action|background)\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s"\'>]+)/i',
            static function (array $a) use ($sub): string {
                $quoted = $a[3][0] === '"' || $a[3][0] === "'";
                $inner  = $quoted ? substr($a[3], 1, -1) : $a[3];
                $new    = $sub($inner, true);
                if ($new === $inner) {
                    return $a[0];
                }
                $url   = html_entity_decode($new, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $attr  = strtolower($a[2]);
                $isSrc = in_array($attr, ['src', 'srcset', 'poster', 'background'], true);
                if ($attr === 'srcset') {
                    // "url 1x, url 2x": every candidate URL must be a safe image source.
                    $ok = true;
                    foreach (explode(',', $url) as $cand) {
                        $u = preg_split('/\s+/', trim($cand), 2)[0] ?? '';
                        $ok = $ok && ($u === '' || HtmlSanitizer::isSafeImageSrc($u));
                    }
                } else {
                    $ok = $isSrc ? HtmlSanitizer::isSafeImageSrc($url) : HtmlSanitizer::isSafeHref($url);
                }
                if (!$ok) {
                    $new = $isSrc ? '' : '#';
                }
                $q = $quoted ? $a[3][0] : '"';
                return $a[1] . $q . $new . $q;
            },
            $html
        );
        return $sub($html, false);
    }
}
