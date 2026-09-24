<?php

namespace ApiGoat\Mail;

/**
 * An email's HTML, made safe to DISPLAY inside a sandboxed iframe while
 * keeping the way it was designed to look.
 *
 * This is deliberately NOT HtmlSanitizer: that allowlist pass exists for
 * markup rendered inline in our own pages and drops <style>, most attributes
 * and every CSS property a mail template lives on — the result is unstyled
 * tables. A mail viewer shows the message inside an <iframe sandbox> (no
 * scripts can run, no forms submit, no top navigation) with a Content-
 * Security-Policy of its own, so what remains to remove here is what the
 * sandbox and CSP do not cover:
 *
 *   - active content: script, iframe/object/embed/applet, form controls,
 *     external stylesheets/links, meta (refresh / their own CSP), base
 *   - every on* handler and every URL attribute with a scheme other than
 *     http(s)/mailto/tel (or data:image/… for src)
 *   - CSS escape hatches: expression(), behavior:, -moz-binding, @import,
 *     url() with a non-http(s)/data scheme
 *
 * Images are blocked by default (tracking pixels): their src moves to
 * data-gm-src behind a transparent 1x1 placeholder, so nothing renders as a
 * broken icon, and the viewer's "Show images" swaps them back (a plain
 * string replacement — see IMG_PLACEHOLDER — and widens the CSP img-src).
 * The other ways a message reaches the network go the same way rather than
 * resting on the CSP alone: srcset/poster are dropped, <image> is treated as
 * the <img> a browser makes of it, <bgsound> is removed, and a remote
 * `background` attribute or CSS url() is parked behind BLOCKED_PREFIX.
 */
final class MailHtml
{
    /**
     * Transparent 100x1 SVG standing in for a blocked image. Wide and flat on
     * purpose: a banner styled width:100% then collapses to a hairline instead
     * of scaling a 1x1 pixel into a full-width square of nothing.
     */
    public const IMG_PLACEHOLDER = 'data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns=%27http://www.w3.org/2000/svg%27%20width=%27100%27%20height=%271%27/%3E';

    /** CSP for the frame document: nothing loads from the network. */
    public const CSP_BLOCKED = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:";
    /** CSP once the viewer chose to show images (remote images + CSS backgrounds). */
    public const CSP_IMAGES  = "default-src 'none'; img-src data: http: https:; style-src 'unsafe-inline'; font-src data:";

    /**
     * Prefix parking a remote URL that is not an <img src> while images are
     * blocked: `background="…"` and CSS url(…). An unknown scheme never
     * reaches the network, and removing the prefix (withImages) restores the
     * original byte for byte. Any occurrence in the incoming message is
     * deleted first, so a sender cannot pre-park a URL of their own.
     */
    public const BLOCKED_PREFIX = 'x-gm-blocked:';

    private const REMOVE = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'template',
        'input', 'button', 'select', 'textarea', 'option', 'link', 'meta', 'base', 'audio', 'video', 'source', 'track',
        'svg', 'math', 'canvas',
    ];
    /**
     * Dropped, children kept. bgsound is here rather than in REMOVE: libxml
     * does not know it is void, so the rest of the message parses as its
     * children and removing it would delete the message.
     */
    private const UNWRAP = ['form', 'bgsound'];
    private const URL_ATTRS = ['href', 'src', 'action', 'formaction', 'background', 'poster', 'xlink:href', 'srcset', 'ping', 'longdesc', 'usemap'];

    /**
     * @param string $html   the message as stored/fetched (a full document or a fragment)
     * @param bool   $images false ⇒ images replaced by the placeholder (default)
     * @param string $reset  extra CSS injected FIRST in <head> (the viewer's base font/reset)
     * @return string a complete HTML document for iframe[srcdoc]
     */
    public static function defuse(string $html, bool $images = false, string $reset = ''): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc  = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $ok   = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return self::document('<pre>' . htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8') . '</pre>', $images, $reset);
        }
        foreach ($doc->childNodes as $n) {          // the encoding PI we prepended
            if ($n instanceof \DOMProcessingInstruction) { $doc->removeChild($n); break; }
        }

        $xp = new \DOMXPath($doc);
        // 1. active / structural elements, comments (MSO conditionals etc.)
        foreach (iterator_to_array($xp->query('//comment()')) as $c) {
            $c->parentNode?->removeChild($c);
        }
        foreach (iterator_to_array($xp->query('//*')) as $el) {
            if (!$el instanceof \DOMElement || $el->parentNode === null) continue;
            $name = strtolower($el->localName ?: $el->nodeName);
            if (in_array($name, self::REMOVE, true)) {
                $el->parentNode->removeChild($el);
            } elseif (in_array($name, self::UNWRAP, true)) {
                while ($el->firstChild) { $el->parentNode->insertBefore($el->firstChild, $el); }
                $el->parentNode->removeChild($el);
            }
        }
        // 2. attributes: handlers, URL schemes, style escape hatches, images
        foreach (iterator_to_array($xp->query('//*')) as $el) {
            if (!$el instanceof \DOMElement) continue;
            foreach (iterator_to_array($el->attributes) as $attr) {
                $an = strtolower($attr->name);
                $av = $attr->value;
                if (stripos($av, self::BLOCKED_PREFIX) !== false) {
                    $av = str_ireplace(self::BLOCKED_PREFIX, '', $av);
                    $el->setAttribute($attr->name, $av);
                }
                if (str_starts_with($an, 'on') || str_starts_with($an, 'data-gm-')) {
                    $el->removeAttribute($attr->name);
                } elseif (in_array($an, self::URL_ATTRS, true)) {
                    if (!self::urlAllowed($av, $an === 'src' || $an === 'srcset' || $an === 'background' || $an === 'poster')) {
                        $el->removeAttribute($attr->name);
                    } elseif (!$images && ($an === 'srcset' || $an === 'poster')) {
                        // Every candidate is a remote fetch; src (parked below)
                        // is what "Show images" brings back.
                        $el->removeAttribute($attr->name);
                    } elseif (!$images && $an === 'background' && !str_starts_with(strtolower(trim($av)), 'data:')) {
                        $el->setAttribute($attr->name, self::BLOCKED_PREFIX . $av);
                    }
                } elseif ($an === 'style') {
                    $el->setAttribute('style', self::cleanCss($av, $images));
                }
            }
            $name = strtolower($el->localName ?: $el->nodeName);
            if ($name === 'a') {
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer');
            }
            // <image> is what a browser's parser turns into <img>.
            if (($name === 'img' || $name === 'image') && !$images && $el->hasAttribute('src')) {
                $src = $el->getAttribute('src');
                if (!str_starts_with(strtolower($src), 'data:')) {
                    // Re-added (not edited in place) so src and data-gm-src are
                    // adjacent — withImages() relies on that exact adjacency.
                    $el->removeAttribute('src');
                    $el->setAttribute('src', self::IMG_PLACEHOLDER);
                    $el->setAttribute('data-gm-src', $src);
                }
            }
        }
        // 3. <style> blocks
        foreach (iterator_to_array($xp->query('//style')) as $st) {
            $st->textContent = self::cleanCss($st->textContent, $images);
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $head = $doc->getElementsByTagName('head')->item(0);
        $inner = '';
        if ($body) {
            foreach ($body->childNodes as $c) { $inner .= $doc->saveHTML($c); }
        } else {
            foreach ($doc->childNodes as $c) { $inner .= $doc->saveHTML($c); }
        }
        $styles = '';
        if ($head) {
            foreach ($head->getElementsByTagName('style') as $st) { $styles .= $doc->saveHTML($st); }
        }
        $bodyAttrs = '';
        if ($body instanceof \DOMElement) {
            foreach (['style', 'bgcolor', 'class', 'dir', 'lang'] as $ba) {
                if ($body->hasAttribute($ba)) { $bodyAttrs .= ' ' . $ba . '="' . htmlspecialchars($body->getAttribute($ba), ENT_QUOTES, 'UTF-8') . '"'; }
            }
        }
        return self::document($inner, $images, $reset, $styles, $bodyAttrs);
    }

    /** Flip a blocked frame document to one that shows images (the client does the same on the srcdoc string). */
    public static function withImages(string $frameDocument): string
    {
        return str_replace(
            [self::CSP_BLOCKED, ' src="' . self::IMG_PLACEHOLDER . '" data-gm-src="', self::BLOCKED_PREFIX],
            [self::CSP_IMAGES, ' src="', ''],
            $frameDocument
        );
    }

    private static function document(string $inner, bool $images, string $reset, string $styles = '', string $bodyAttrs = ''): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta http-equiv="Content-Security-Policy" content="' . ($images ? self::CSP_IMAGES : self::CSP_BLOCKED) . '">'
            . '<base target="_blank">'
            . ($reset !== '' ? '<style>' . $reset . '</style>' : '')
            . $styles
            . '</head><body' . $bodyAttrs . '>' . $inner . '</body></html>';
    }

    private static function urlAllowed(string $url, bool $allowDataImage): bool
    {
        $u = strtolower(trim(preg_replace('/[\x00-\x20]+/', '', $url) ?? ''));
        if ($u === '' || $u[0] === '#' || $u[0] === '/' || !preg_match('/^[a-z][a-z0-9+.-]*:/', $u)) {
            return true;                                   // relative / anchor / scheme-less
        }
        if (str_starts_with($u, 'http:') || str_starts_with($u, 'https:') || str_starts_with($u, 'mailto:') || str_starts_with($u, 'tel:')) {
            return true;
        }
        return $allowDataImage && str_starts_with($u, 'data:image/');
    }

    private static function cleanCss(string $css, bool $images = true): string
    {
        // SECURITY: resolve escapes that spell a name (u\72 l(, @\69mport, \62 ehavior)
        // so the filters below see what the browser's tokenizer sees. Only
        // escapes decoding to a letter are rewritten: same meaning (a \31 0px class
        // or a \" inside a string stays escaped).
        // Any other escaped character (\\, \", an escaped newline) is consumed
        // as a unit and kept: "u\\72 l(" is a literal backslash, and must not
        // decode to "u\rl(" (= url( to the browser).
        $css = preg_replace_callback('/\\\\(?:([0-9a-fA-F]{1,6})(?:\r\n|[ \t\r\n\f])?|([g-zG-Z])|(.))/s', static function (array $m): string {
            if (($m[3] ?? '') !== '') {
                return $m[0];
            }
            if (($m[2] ?? '') !== '') {
                return $m[2];
            }
            $c = hexdec($m[1]);
            return $c < 0x80 && preg_match('/^[A-Za-z]$/', chr((int) $c)) ? chr((int) $c) : $m[0];
        }, $css) ?? '';
        // SECURITY: removals run to a fixpoint — "@imp@import;ort" (or a split
        // BLOCKED_PREFIX) must not reassemble what was just removed.
        do {
            $before = $css;
            $css = str_ireplace(self::BLOCKED_PREFIX, '', $css);
            $css = preg_replace('/expression\s*\(/i', 'expression-blocked(', $css) ?? '';
            $css = preg_replace('/-moz-binding\s*:[^;}]*;?/i', '', $css) ?? '';
            $css = preg_replace('/behavior\s*:[^;}]*;?/i', '', $css) ?? '';
            $css = preg_replace('/@import[^;]*;?/i', '', $css) ?? '';
        } while ($css !== $before);
        // url(): keep http(s)/data, drop the rest (javascript:, vbscript:, file:)
        // Images blocked: a remote url() is parked (BLOCKED_PREFIX), not dropped,
        // so "Show images" restores backgrounds too.
        $css = preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', static function (array $m) use ($images): string {
            $u = strtolower(trim($m[2]));
            if (str_starts_with($u, 'data:image/')) {
                return $m[0];
            }
            if (!str_starts_with($u, 'http') && !str_starts_with($u, '//')) {
                return 'none';
            }
            return $images ? $m[0] : 'url(' . $m[1] . self::BLOCKED_PREFIX . trim($m[2]) . $m[1] . ')';
        }, $css) ?? '';
        // image-set() / cross-fade() / image() / src() also take a plain string
        // as an image URL ("https://t/px.gif" 1x): same policy as url() — a
        // string that is not http(s)/data:image goes, a remote one is parked
        // while images are blocked.
        $css = preg_replace_callback('/((?:-webkit-)?image-set|cross-fade|image|src)\s*(\((?:[^()]++|(?2))*\))/i', static function (array $m) use ($images): string {
            $args = preg_replace_callback('/(["\'])(.*?)\1/s', static function (array $q) use ($images): string {
                $u = strtolower(trim($q[2]));
                if (str_starts_with($u, 'data:image/')) {
                    return $q[0];
                }
                if (!str_starts_with($u, 'http') && !str_starts_with($u, '//')) {
                    return $q[1] . $q[1];
                }
                return $images ? $q[0] : $q[1] . self::BLOCKED_PREFIX . trim($q[2]) . $q[1];
            }, $m[2]) ?? '';
            return $m[1] . $args;
        }, $css) ?? '';
        // SECURITY: libxml writes <style> text raw, so a "<" left in the CSS can
        // close the element ("</style><img onerror=…>"). \3C is the same
        // character to CSS and can never form a tag.
        return str_replace('<', '\\3C ', $css);
    }
}
