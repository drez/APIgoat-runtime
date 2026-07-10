<?php

namespace ApiGoat\Document;

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
        return (string) preg_replace_callback(
            '/\{([^{}]{1,150})\}/u',
            static function (array $m) use ($norm): string {
                $key = mb_strtolower(trim($m[1]));
                return array_key_exists($key, $norm)
                    ? htmlspecialchars($norm[$key], ENT_QUOTES, 'UTF-8')
                    : $m[0];
            },
            $html
        );
    }
}
