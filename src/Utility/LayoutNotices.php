<?php

namespace ApiGoat\Utility;

/**
 * Page-wide notices rendered by {@see BuilderLayout::render()} on every
 * admin page, directly under the topbar.
 *
 * The registry exists because a project's *background* work can fail in a
 * way no screen reports: a mailbox whose IMAP auth expires, a queue whose
 * worker died, a sync that silently stopped. The failure is visible in a
 * detail panel nobody opens, so it is discovered days later by its
 * consequences. A provider registered here puts that condition in front of
 * the operator on whatever page they are already on.
 *
 * Contract for a provider: a callable returning a list of notices, each
 *
 *     ['tone' => 'warn'|'error'|'info', 'text' => '…', 'href' => '…'?]
 *
 * `text` and `href` are PLAIN values — this class escapes them. A provider
 * that throws is skipped: a broken health check must never take down every
 * page in the app. Returning `[]` renders nothing.
 *
 * With no provider registered, render() returns '' and the layout is
 * byte-identical to before — every project that does not opt in is
 * unaffected.
 */
class LayoutNotices
{
    /** Most notices rendered at once; a provider bug must not produce a wall of text. */
    private const MAX = 5;

    /** @var array<int, callable():array> */
    private static $providers = [];

    /** Register a provider. Called from a project's bootstrap/dependencies. */
    public static function add(callable $provider): void
    {
        self::$providers[] = $provider;
    }

    /** Drop every provider (tests). */
    public static function reset(): void
    {
        self::$providers = [];
    }

    /** True when at least one provider is registered. */
    public static function hasProviders(): bool
    {
        return self::$providers !== [];
    }

    /**
     * Collect from every provider, in registration order.
     *
     * @return array<int, array{tone:string, text:string, href:string}>
     */
    public static function collect(): array
    {
        $out = [];
        foreach (self::$providers as $provider) {
            try {
                $notices = $provider();
            } catch (\Throwable $e) {
                // A health probe that throws must not break the page it
                // decorates. Log and carry on with the other providers.
                error_log('[LayoutNotices] provider failed: ' . $e->getMessage());
                continue;
            }
            if (!is_array($notices)) {
                continue;
            }
            foreach ($notices as $n) {
                if (!is_array($n) || !isset($n['text']) || trim((string) $n['text']) === '') {
                    continue;
                }
                $tone = isset($n['tone']) ? (string) $n['tone'] : 'warn';
                $out[] = [
                    'tone' => in_array($tone, ['warn', 'error', 'info'], true) ? $tone : 'warn',
                    'text' => (string) $n['text'],
                    'href' => isset($n['href']) ? (string) $n['href'] : '',
                ];
                if (count($out) >= self::MAX) {
                    return $out;
                }
            }
        }
        return $out;
    }

    /** Rendered HTML for the layout, or '' when there is nothing to say. */
    public static function render(): string
    {
        $notices = self::collect();
        if ($notices === []) {
            return '';
        }

        $items = '';
        foreach ($notices as $n) {
            $icon = $n['tone'] === 'error' ? 'ri-error-warning-line' : ($n['tone'] === 'info' ? 'ri-information-line' : 'ri-alert-line');
            $text = '<i class="' . $icon . '"></i><span>' . htmlspecialchars($n['text'], ENT_QUOTES, 'UTF-8') . '</span>';
            if ($n['href'] !== '') {
                // Relative or same-origin only: a notice must not become an
                // outbound link surface for whatever produced the string.
                $href = self::safeHref($n['href']);
                if ($href !== '') {
                    $text .= ' <a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(_('Fix'), ENT_QUOTES, 'UTF-8') . '</a>';
                }
            }
            $items .= '<div class="gc-notice is-' . $n['tone'] . '">' . $text . '</div>';
        }

        return '<div class="gc-notices" role="status">' . $items . '</div>';
    }

    /**
     * Same-origin only. A relative path passes; an http(s) URL passes only
     * when its host is this server (providers build links from _SITE_URL,
     * which is absolute). Anything else — `javascript:`, a protocol-relative
     * `//evil`, another host — is dropped rather than sanitised: a health
     * notice has no reason to leave the app.
     */
    private static function safeHref(string $href): string
    {
        $href = trim($href);
        if ($href === '' || strncmp($href, '//', 2) === 0) {
            return '';
        }
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $href)) {
            return $href; // relative path
        }
        $parts = parse_url($href);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return '';
        }
        $self = (string) ($_SERVER['SERVER_NAME'] ?? '');
        return ($self !== '' && strcasecmp($parts['host'], $self) === 0) ? $href : '';
    }
}
