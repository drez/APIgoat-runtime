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

    private const REMOVE = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'template',
        'input', 'button', 'select', 'textarea', 'option', 'link', 'meta', 'base', 'audio', 'video', 'source', 'track',
        'svg', 'math', 'canvas',
    ];
    private const UNWRAP = ['form'];
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
                if (str_starts_with($an, 'on')) {
                    $el->removeAttribute($attr->name);
                } elseif (in_array($an, self::URL_ATTRS, true)) {
                    if (!self::urlAllowed($av, $an === 'src' || $an === 'srcset' || $an === 'background' || $an === 'poster')) {
                        $el->removeAttribute($attr->name);
                    }
                } elseif ($an === 'style') {
                    $el->setAttribute('style', self::cleanCss($av));
                }
            }
            $name = strtolower($el->localName ?: $el->nodeName);
            if ($name === 'a') {
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer');
            }
            if ($name === 'img' && !$images && $el->hasAttribute('src')) {
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
            $st->textContent = self::cleanCss($st->textContent);
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
            [self::CSP_BLOCKED, ' src="' . self::IMG_PLACEHOLDER . '" data-gm-src="'],
            [self::CSP_IMAGES, ' src="'],
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

    private static function cleanCss(string $css): string
    {
        $css = preg_replace('/expression\s*\(/i', 'expression-blocked(', $css) ?? $css;
        $css = preg_replace('/-moz-binding\s*:[^;}]*;?/i', '', $css) ?? $css;
        $css = preg_replace('/behavior\s*:[^;}]*;?/i', '', $css) ?? $css;
        $css = preg_replace('/@import[^;]*;?/i', '', $css) ?? $css;
        // url(): keep http(s)/data, drop the rest (javascript:, vbscript:, file:)
        $css = preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', static function (array $m): string {
            $u = strtolower(trim($m[2]));
            return (str_starts_with($u, 'http') || str_starts_with($u, 'data:image/') || str_starts_with($u, '//')) ? $m[0] : 'none';
        }, $css) ?? $css;
        return $css;
    }
}
