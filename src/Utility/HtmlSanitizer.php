<?php

namespace ApiGoat\Utility;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Authoritative server-side sanitizer for stored WYSIWYG / rich-text HTML.
 *
 * WYSIWYG columns (is_wysiwyg_colunms / mce_columns) used to be persisted
 * verbatim on the "trusted admin author" assumption; nothing on the write path
 * cleaned them, so a caller reaching the generic API directly could store
 * arbitrary markup that then executed on any raw-HTML render surface (the mobile
 * WebView, documents/PDF, or any consumer using innerHTML). This class is the
 * single write-side chokepoint: the GoatCheese behavior calls clean() in the
 * generated model's preSave, so every write path (GUI, JSON API, CLI, bulk) is
 * covered.
 *
 * It is an ALLOWLIST DOM sanitizer, not a regex denylist: the input is parsed
 * with ext-dom, the tree is walked, and anything not explicitly permitted is
 * dropped. Elements not on the allowlist are unwrapped (their text kept) unless
 * they are actively dangerous (script/style/svg/…), which are removed whole.
 * Attributes are per-element allowlisted; every on* handler is stripped; href /
 * src are scheme-validated; style is filtered to a safe property/value set.
 *
 * Self-contained (no dependency) so it deploys on the next runtime pull. The
 * public seam is clean(); a heavier library (HTMLPurifier) could be dropped in
 * behind it without touching callers.
 */
final class HtmlSanitizer
{
    /** Elements kept (lowercase). Anything else is unwrapped or (if dangerous) removed. */
    private const ALLOWED_ELEMENTS = [
        'p', 'br', 'hr', 'span', 'div',
        'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins', 'sub', 'sup', 'small', 'mark',
        'blockquote', 'pre', 'code',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li',
        'a', 'img',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
        'figure', 'figcaption',
    ];

    /**
     * Elements removed together with their entire subtree (not unwrapped): they
     * carry executable content or are known mutation-XSS vectors.
     */
    private const DANGEROUS_ELEMENTS = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'template', 'noscript',
        'form', 'input', 'button', 'textarea', 'select', 'option',
        'link', 'meta', 'base', 'title', 'head',
        'svg', 'math', 'frame', 'frameset', 'audio', 'video', 'source', 'track', 'canvas',
    ];

    /** Per-element attribute allowlist. '*' applies to every allowed element. */
    private const ALLOWED_ATTRIBUTES = [
        '*'   => ['title', 'dir', 'lang'],
        'a'   => ['href', 'name', 'target'],
        'img' => ['src', 'alt', 'width', 'height'],
        'td'  => ['colspan', 'rowspan'],
        'th'  => ['colspan', 'rowspan', 'scope'],
        'col' => ['span'],
        'colgroup' => ['span'],
        'ol'  => ['start', 'type'],
    ];

    /** URL schemes permitted in href. Relative / anchor / scheme-less pass. */
    private const ALLOWED_HREF_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** CSS properties kept in a sanitized style attribute. */
    private const ALLOWED_STYLE_PROPS = [
        'text-align', 'text-decoration', 'font-weight', 'font-style', 'font-size',
        'color', 'background-color', 'vertical-align', 'width', 'height',
        'margin', 'margin-left', 'margin-right', 'margin-top', 'margin-bottom',
        'padding', 'padding-left', 'padding-right', 'padding-top', 'padding-bottom',
        'border', 'border-color', 'border-width', 'border-style', 'list-style-type',
    ];

    /**
     * Return a sanitized copy of $html safe to render as HTML on any surface.
     * Non-string / empty input returns '' (or the original scalar as string).
     */
    public static function clean($html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        // Force UTF-8 interpretation and avoid DOMDocument injecting <html>/<body>
        // or a doctype into the fragment. libxml warnings on unknown tags are noise.
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__gc_root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            // Unparseable → fail closed to plain text (tags stripped, entities encoded).
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }

        $root = $doc->getElementById('__gc_root');
        if (!$root) {
            $root = $doc->documentElement;
        }
        if ($root instanceof DOMElement) {
            self::cleanChildren($root, $doc);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /**
     * Sanitize every child of $node in place. Disallowed-but-safe elements are
     * unwrapped (children promoted); dangerous ones are removed with content.
     */
    private static function cleanChildren(DOMNode $node, DOMDocument $doc): void
    {
        // Snapshot: the live childNodes list mutates as we remove/replace.
        $children = [];
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                self::cleanElement($child, $doc);
            } elseif ($child->nodeType === XML_COMMENT_NODE
                || $child->nodeType === XML_PI_NODE
                || $child->nodeType === XML_CDATA_SECTION_NODE) {
                // Comments can hide conditional-comment / mXSS payloads; drop them.
                $node->removeChild($child);
            }
            // Text nodes are kept as-is; saveHTML re-encodes them safely.
        }
    }

    private static function cleanElement(DOMElement $el, DOMDocument $doc): void
    {
        $tag = strtolower($el->localName ?? $el->nodeName);

        if (in_array($tag, self::DANGEROUS_ELEMENTS, true)) {
            $el->parentNode->removeChild($el);
            return;
        }

        if (!in_array($tag, self::ALLOWED_ELEMENTS, true)) {
            // Unknown-but-not-dangerous: recurse, then unwrap (keep the text/children).
            self::cleanChildren($el, $doc);
            self::unwrap($el);
            return;
        }

        // Allowed element: scrub its attributes, then recurse into children.
        self::cleanAttributes($el, $tag);
        self::cleanChildren($el, $doc);
    }

    private static function cleanAttributes(DOMElement $el, string $tag): void
    {
        $allowed = array_merge(
            self::ALLOWED_ATTRIBUTES['*'],
            self::ALLOWED_ATTRIBUTES[$tag] ?? []
        );
        // 'style' is allowed on any element but goes through a value filter.
        $allowStyle = true;

        $attrs = [];
        foreach ($el->attributes as $attr) {
            $attrs[] = $attr->nodeName;
        }

        foreach ($attrs as $name) {
            $lname = strtolower($name);

            if ($lname === 'style') {
                if ($allowStyle) {
                    $safe = self::cleanStyle($el->getAttribute($name));
                    if ($safe === '') {
                        $el->removeAttribute($name);
                    } else {
                        $el->setAttribute('style', $safe);
                    }
                } else {
                    $el->removeAttribute($name);
                }
                continue;
            }

            // Strip every event handler and anything not explicitly allowed.
            if (strncmp($lname, 'on', 2) === 0 || !in_array($lname, $allowed, true)) {
                $el->removeAttribute($name);
                continue;
            }

            // Scheme-validate URL-bearing attributes.
            if ($lname === 'href') {
                if (!self::isSafeHref($el->getAttribute($name))) {
                    $el->removeAttribute($name);
                }
            } elseif ($lname === 'src') {
                if (!self::isSafeImageSrc($el->getAttribute($name))) {
                    $el->removeAttribute($name);
                }
            } elseif ($lname === 'target') {
                // A target="_blank" without rel is a reverse-tabnabbing vector.
                $el->setAttribute('rel', 'noopener noreferrer');
            }
        }

        // A link whose href was rejected is a bare, misleading anchor — drop target/rel noise.
        if ($tag === 'a' && !$el->hasAttribute('href')) {
            $el->removeAttribute('target');
            $el->removeAttribute('rel');
        }
    }

    private static function isSafeHref(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        // Relative, root-relative, anchor, or query — no scheme, safe.
        if ($url[0] === '/' || $url[0] === '#' || $url[0] === '?' || $url[0] === '.') {
            return !self::hasControlChars($url);
        }
        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*):#', $url, $m)) {
            return in_array(strtolower($m[1]), self::ALLOWED_HREF_SCHEMES, true)
                && !self::hasControlChars($url);
        }
        // No scheme and not obviously relative (e.g. "example.com/path") — treat as relative.
        return !self::hasControlChars($url);
    }

    private static function isSafeImageSrc(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if ($url[0] === '/' || $url[0] === '.' || $url[0] === '?') {
            return !self::hasControlChars($url);
        }
        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*):#', $url, $m)) {
            $scheme = strtolower($m[1]);
            if ($scheme === 'http' || $scheme === 'https') {
                return !self::hasControlChars($url);
            }
            // Only base64 raster data URIs — never data:text/html or SVG.
            if ($scheme === 'data') {
                return (bool) preg_match('#^data:image/(png|jpe?g|gif|webp|bmp);base64,[a-zA-Z0-9+/=\s]+$#i', $url);
            }
            return false;
        }
        return !self::hasControlChars($url);
    }

    /**
     * Whitespace/control chars smuggle "java\tscript:" past a scheme check —
     * reject any URL carrying them (a legitimate URL never does).
     */
    private static function hasControlChars(string $s): bool
    {
        return (bool) preg_match('/[\x00-\x1F\x7F]/', $s);
    }

    private static function cleanStyle(string $style): string
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            if (strpos($decl, ':') === false) {
                continue;
            }
            [$prop, $value] = explode(':', $decl, 2);
            $prop = strtolower(trim($prop));
            $value = trim($value);
            if ($prop === '' || $value === ''
                || !in_array($prop, self::ALLOWED_STYLE_PROPS, true)) {
                continue;
            }
            // Reject anything that can fetch, script, or break out of the value.
            if (preg_match('/url\s*\(|expression|javascript:|@import|[<>{}\\\\]|[\x00-\x1F]/i', $value)) {
                continue;
            }
            $out[] = $prop . ': ' . $value;
        }
        return implode('; ', $out);
    }

    /** Replace $el with its child nodes (keeps text of an unknown wrapper). */
    private static function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }
}
