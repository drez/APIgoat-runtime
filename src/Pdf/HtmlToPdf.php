<?php

namespace ApiGoat\Pdf;

/**
 * HTML → PDF bytes via dompdf. Generalized from apicrm's QuotePdf: DejaVu Sans
 * (bundled with dompdf) so accented French renders correctly; letter portrait.
 *
 * dompdf is a PROJECT composer dependency (apicrm ships ^2, apigoatacc ^3 —
 * the API used here is stable across both). The runtime soft-requires it so
 * projects without with_pdf don't pay the dependency.
 *
 * SSRF hardening: the rendered HTML is template-driven and can carry
 * user-supplied fields (quote/invoice notes, product answers, addresses). With
 * dompdf's remote fetching enabled, an injected <img src> / CSS url() would let
 * dompdf issue server-side requests. We therefore (a) never allow file:// (no
 * local-file disclosure via <img src="file:///etc/passwd">), and (b) gate every
 * http(s) fetch on a rule that rejects non-public hosts (loopback, private,
 * link-local incl. the cloud metadata endpoint, and other reserved ranges).
 * Public remote images still load; data: URIs (the logo path) are unaffected.
 *
 * The browser engines (tried FIRST) have no such protocol hook, so the same
 * posture is applied to the document itself before they see it — see
 * neutralise() — on top of the engines' own switches (JavaScript off, no
 * local file access).
 */
final class HtmlToPdf
{
    /** Engines in auto-selection order; first whose binary exists wins, dompdf last. */
    public const ENGINES = ['wkhtmltopdf', 'chrome', 'dompdf'];

    /**
     * HTML → PDF bytes. Engine: PDF_ENGINE env (wkhtmltopdf | chrome | dompdf |
     * auto, default auto). Browser engines (wkhtmltopdf = WebKit, chrome) lay
     * the page out exactly like the printable page in a browser; dompdf is the
     * pure-PHP fallback with its own, coarser CSS engine.
     */
    public function render(string $html): string
    {
        return match (self::engine()) {
            'wkhtmltopdf' => $this->renderWkhtmltopdf($html),
            'chrome'      => $this->renderChrome($html),
            default       => $this->renderDompdf($html),
        };
    }

    /** Resolved engine name for this host (PDF_ENGINE env, then binary discovery). */
    public static function engine(): string
    {
        return self::engineFor(getenv('PDF_ENGINE') ?: ($_ENV['PDF_ENGINE'] ?? null), fn(string $e) => self::binary($e) !== null);
    }

    /**
     * Engine choice for a preference + "is this engine available" probe.
     * Unknown/unavailable preferences fall through to auto; dompdf always wins last.
     */
    public static function engineFor(?string $pref, callable $available): string
    {
        $pref = strtolower(trim((string) $pref));
        if ($pref !== '' && $pref !== 'auto' && in_array($pref, self::ENGINES, true)
            && ($pref === 'dompdf' || $available($pref))) {
            return $pref;
        }
        foreach (self::ENGINES as $e) {
            if ($e === 'dompdf' || $available($e)) {
                return $e;
            }
        }
        return 'dompdf';
    }

    /**
     * An engine's executable (PDF_<ENGINE>_BIN env, else the usual names
     * resolved through PATH), or null. Probed by RUNNING `<bin> --version`
     * rather than is_file(): shared hosts (ISPConfig) put an open_basedir on
     * PHP that hides /usr/bin from stat() while exec is still allowed.
     * Memoized per request.
     */
    public static function binary(string $engine): ?string
    {
        static $memo = [];
        if (array_key_exists($engine, $memo)) {
            return $memo[$engine];
        }
        $env = getenv('PDF_' . strtoupper($engine) . '_BIN') ?: ($_ENV['PDF_' . strtoupper($engine) . '_BIN'] ?? '');
        $names = match ($engine) {
            'wkhtmltopdf' => ['wkhtmltopdf'],
            'chrome'      => ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'chrome'],
            default       => [],
        };
        foreach ($env !== '' ? [$env] : $names as $c) {
            if (self::runs($c)) {
                return $memo[$engine] = $c;
            }
        }
        return $memo[$engine] = null;
    }

    /** True when `<bin> --version` starts and exits 0 (PATH-resolved when $bin is a bare name). */
    private static function runs(string $bin): bool
    {
        if ($bin === '' || !function_exists('proc_open')) {
            return false;
        }
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open([$bin, '--version'], $spec, $pipes, null, self::env([]));
        if (!is_resource($proc)) {
            return false;
        }
        // stdin is a pipe (closed at once), not /dev/null: open_basedir on
        // shared hosts forbids opening /dev/null and proc_open would fail.
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($proc) === 0;
    }

    /** Environment for the CLI engines: a sane PATH + UTF-8 locale + overrides. */
    private static function env(array $extra): array
    {
        $path = getenv('PATH') ?: '';
        foreach (['/usr/local/bin', '/usr/bin', '/bin', '/opt/homebrew/bin', '/snap/bin'] as $d) {
            if (!in_array($d, explode(':', $path), true)) {
                $path = ($path === '' ? '' : $path . ':') . $d;
            }
        }
        return array_merge(['PATH' => $path, 'LANG' => 'C.UTF-8'], $extra);
    }

    // ── wkhtmltopdf (WebKit) ─────────────────────────────────────────────

    /**
     * wkhtmltopdf renders like a browser, with two quirks handled here:
     *  - @font-face is broken in the (unpatched-Qt) Debian build — text set in
     *    a web font silently disappears — so every @font-face rule is dropped
     *    and the project's fonts (public/fonts/**\/*.ttf) are handed to
     *    fontconfig through a per-project FONTCONFIG_FILE instead; the same
     *    family names then resolve on screen (via @font-face) and on paper.
     *  - the SSRF/local-file posture matches dompdf's: JavaScript off, file://
     *    off (the document is rendered from a temp file, fonts come from
     *    fontconfig, images stay http(s)/data:).
     */
    private function renderWkhtmltopdf(string $html): string
    {
        $bin  = self::binary('wkhtmltopdf');
        $work = self::workDir('wkhtmltopdf');
        if ($bin === null || $work === null) {
            return $this->renderDompdf($html);
        }
        $html = (string) preg_replace('/@font-face\s*\{[^{}]*\}/i', '', $html);
        $html = self::neutralise($html);
        [$size, $margins] = self::paper();
        $scale = self::wkScale();
        $html = self::fitToPaper($html, self::contentWidthPx($size, $margins), $scale);
        $wkMargins = self::wkMargins($margins, $scale); // the build inflates margins by 1/scale too

        $fontsDir = defined('_BASE_DIR') ? rtrim((string) _BASE_DIR, '/') . '/public/fonts' : '';
        $conf = $work . '/fonts.conf';
        file_put_contents($conf, self::fontsConf(is_dir($fontsDir) ? $fontsDir : null, $work . '/fc-cache'));
        if (!is_dir($work . '/fc-cache')) {
            @mkdir($work . '/fc-cache', 0775, true);
        }

        $in  = $work . '/' . uniqid('doc_', true) . '.html';
        $out = substr($in, 0, -5) . '.pdf';
        file_put_contents($in, $html);
        $cmd = [
            $bin, '-q', '--disable-javascript', '--disable-local-file-access',
            '--load-error-handling', 'ignore', '--load-media-error-handling', 'ignore',
            '--encoding', 'utf-8', '--page-size', $size,
            '-T', $wkMargins[0], '-R', $wkMargins[1], '-B', $wkMargins[2], '-L', $wkMargins[3],
            $in, $out,
        ];
        try {
            $err = self::run($cmd, ['FONTCONFIG_FILE' => $conf, 'XDG_RUNTIME_DIR' => $work, 'HOME' => $work]);
            $bytes = is_file($out) ? (string) file_get_contents($out) : '';
        } finally {
            @unlink($in);
            @unlink($out);
        }
        if (!str_starts_with($bytes, '%PDF')) {
            throw new \RuntimeException('wkhtmltopdf produced no PDF: ' . trim($err));
        }
        return $bytes;
    }

    /**
     * The Debian (unpatched-Qt) wkhtmltopdf ignores --zoom/--dpi and draws
     * CSS px at PDF_WK_SCALE × 1/96 in (0.725 measured; a patched build is 1),
     * then "smart-shrinks" anything wider than the printable area. Pinning the
     * layout to the printable width and zooming by 1/scale makes 1 CSS px =
     * 1/96 in on paper — the same font size, padding and margins as the
     * printable page in a browser.
     */
    public static function wkScale(): float
    {
        $v = (float) (getenv('PDF_WK_SCALE') ?: ($_ENV['PDF_WK_SCALE'] ?? 0.725));
        return $v > 0.1 && $v <= 1.0 ? $v : 0.725;
    }

    public static function fitToPaper(string $html, int $contentWidthPx, float $scale): string
    {
        // Layout in zoomed px = printable width: paper px = width × zoom × scale = width.
        $zoom = round(1 / $scale, 4);
        $css = '<style>html{zoom:' . $zoom . '}html,body{margin:0;padding:0}body{width:' . $contentWidthPx . 'px}</style>';
        return str_contains($html, '</head>') ? preg_replace('#</head>#i', $css . '</head>', $html, 1) : $css . $html;
    }

    /**
     * Margins to hand to wkhtmltopdf so the printed margins come out as
     * requested: the same DPI quirk that shrinks CSS px inflates the margin
     * options by 1/scale, so pre-shrink them. ["18mm", …] × 0.725 → "13.05mm".
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    public static function wkMargins(array $margins, float $scale): array
    {
        $out = [];
        foreach ([0, 1, 2, 3] as $i) {
            $out[$i] = round(self::toMm((string) ($margins[$i] ?? '0')) * $scale, 2) . 'mm';
        }
        return $out;
    }

    /** Printable width in CSS px (96/in) for a paper size and [top, right, bottom, left] margins. */
    public static function contentWidthPx(string $size, array $margins): int
    {
        $widthMm = match (strtolower($size)) {
            'a4'     => 210.0,
            'a5'     => 148.0,
            'legal'  => 215.9,
            'letter' => 215.9,
            default  => 215.9,
        };
        $mm = $widthMm - self::toMm((string) ($margins[1] ?? '0')) - self::toMm((string) ($margins[3] ?? '0'));
        return (int) round($mm / 25.4 * 96);
    }

    /** "16mm" | "1.5cm" | "0.5in" | "48px" | "12pt" | bare number (mm) → mm. */
    public static function toMm(string $v): float
    {
        if (!preg_match('/^\s*([0-9.]+)\s*(mm|cm|in|px|pt)?\s*$/i', $v, $m)) {
            return 0.0;
        }
        $n = (float) $m[1];
        return match (strtolower($m[2] ?? 'mm')) {
            'cm' => $n * 10,
            'in' => $n * 25.4,
            'px' => $n / 96 * 25.4,
            'pt' => $n / 72 * 25.4,
            default => $n,
        };
    }

    /** fontconfig config: the system fonts plus the project's fonts dir, cache under the project. */
    public static function fontsConf(?string $fontsDir, string $cacheDir): string
    {
        $dir = $fontsDir !== null ? '<dir>' . htmlspecialchars($fontsDir, ENT_XML1) . '</dir>' : '';
        return '<?xml version="1.0"?><!DOCTYPE fontconfig SYSTEM "fonts.dtd"><fontconfig>'
            . '<include ignore_missing="yes">/etc/fonts/fonts.conf</include>'
            . $dir
            . '<cachedir>' . htmlspecialchars($cacheDir, ENT_XML1) . '</cachedir>'
            . '</fontconfig>';
    }

    // ── headless Chrome ──────────────────────────────────────────────────

    /**
     * Chrome prints the document exactly as the browser shows it; @page
     * margins come from the document's own CSS. The document is a local file,
     * and a file:// page may embed ANY other local file (<img>, <iframe>), so:
     * project fonts are inlined as data: URIs (the only local files it needs —
     * which is what lets --allow-file-access-from-files go), neutralise()
     * removes every other non-public reference, and a CSP (withCsp) has the
     * engine itself refuse scripts, frames, plugins and file: subresources.
     * (--blink-settings=scriptEnabled=false is not an option: headless
     * --print-to-pdf then writes no file at all.)
     */
    private function renderChrome(string $html): string
    {
        $bin  = self::binary('chrome');
        $work = self::workDir('chrome');
        if ($bin === null || $work === null) {
            return $this->renderDompdf($html);
        }
        $html = self::withCsp(self::neutralise(self::inlineProjectFonts($html)));
        $in  = $work . '/' . uniqid('doc_', true) . '.html';
        $out = substr($in, 0, -5) . '.pdf';
        file_put_contents($in, $html);
        $cmd = [
            $bin, '--headless=new', '--no-sandbox', '--disable-gpu', '--no-first-run', '--no-pdf-header-footer',
            '--user-data-dir=' . $work . '/profile',
            '--virtual-time-budget=5000', '--print-to-pdf=' . $out, 'file://' . $in,
        ];
        try {
            $err = self::run($cmd, ['HOME' => $work, 'XDG_RUNTIME_DIR' => $work]);
            $bytes = is_file($out) ? (string) file_get_contents($out) : '';
        } finally {
            @unlink($in);
            @unlink($out);
        }
        if (!str_starts_with($bytes, '%PDF')) {
            throw new \RuntimeException('chrome produced no PDF: ' . trim($err));
        }
        return $bytes;
    }

    /**
     * url(…/public/fonts/<file>.ttf) → url(data:font/ttf;base64,…) for files
     * that really are project fonts (assertProjectFontPath: realpath under
     * public/fonts, .ttf). Anything else is left as written and then judged by
     * neutralise() like any other URL.
     */
    public static function inlineProjectFonts(string $html): string
    {
        if (!defined('_BASE_DIR')) {
            return $html;
        }
        $base = rtrim((string) _BASE_DIR, '/') . '/public/fonts/';
        return (string) preg_replace_callback(
            '#url\(\s*([\'"]?)[^\'")]*?/public/fonts/([^\'")?\#]+)[^\'")]*\1\s*\)#i',
            static function (array $m) use ($base): string {
                $path = $base . rawurldecode($m[2]);
                if (!self::assertProjectFontPath('file://' . $path)[0]) {
                    return $m[0];
                }
                $bytes = @file_get_contents($path);
                return $bytes === false ? $m[0] : 'url(data:font/ttf;base64,' . base64_encode($bytes) . ')';
            },
            $html
        );
    }

    /** Engine-enforced backstop for neutralise(): no script, frame or plugin; images/styles/fonts only from data: and http(s). */
    public const CSP = "default-src 'none'; img-src data: http: https:; style-src 'unsafe-inline' http: https:; font-src data: http: https:";

    /** Put the CSP first in <head> (a policy only governs what follows it). */
    public static function withCsp(string $html): string
    {
        $meta = '<meta http-equiv="Content-Security-Policy" content="' . self::CSP . '">';
        $out = preg_replace('#<head\b[^>]*>#i', '$0' . $meta, $html, 1, $n);
        return ($out !== null && $n === 1) ? $out : $meta . $html;
    }

    // ── document hardening for the browser engines ───────────────────────

    /**
     * Make template-driven HTML safe to hand to a real browser engine. The
     * document can carry user-supplied fields (notes, addresses), the engines
     * fetch whatever it references, and it is rendered from a local file — so
     * <iframe src="file:///etc/passwd"> printed that file into the PDF, and
     * <img src="http://169.254.169.254/…"> was a server-side request.
     *
     *  - active / embedding elements are removed: script, iframe, frame,
     *    frameset, object, embed, applet, base, meta http-equiv=refresh;
     *  - on* handlers are removed;
     *  - every resource URL — src, srcset, poster, data, background, href
     *    (not on <a>/<area>: a link in a PDF fetches nothing), xlink:href, CSS
     *    url() and @import — must be a fragment, a data: URI, or an http(s)
     *    URL that passes $urlRule (default: assertPublicUrl, the dompdf rule).
     *    Everything else — file:, relative paths (they resolve against the
     *    temp file), protocol-relative, other schemes — becomes about:blank.
     *
     * @param callable|null $urlRule fn(string $url): array{0: bool, 1: string}
     */
    public static function neutralise(string $html, ?callable $urlRule = null): string
    {
        $urlRule ??= [self::class, 'assertPublicUrl'];
        $memo = [];
        $ok = static function (string $raw) use ($urlRule, &$memo): bool {
            // What the browser will see: entities decoded, and the whitespace /
            // control characters it strips from a URL removed ("fi&#9;le:").
            $url = (string) preg_replace('/[\x00-\x20]+/', '', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url === '' || $url[0] === '#' || stripos($url, 'data:') === 0) {
                return true;
            }
            if (!preg_match('#^https?://#i', $url) || strpos($url, '\\') !== false) {
                return false;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            return $memo[$host] ??= (bool) ($urlRule($url)[0] ?? false);
        };

        // SECURITY: CSS escapes and (in attributes / SVG) entities are decoded by the
        // engine before it parses url() / @import / image-set(); the regexes below
        // only see the raw text. Reject a document whose decoded form carries more
        // of those constructs than its raw form (u\72 l(, url&#40;, @\69mport).
        $construct = '#url\(|@import|image-set\(#i';
        if (preg_match_all($construct, self::cssDecode(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            > preg_match_all($construct, $html)) {
            throw new \RuntimeException('PDF: the document carries encoded CSS url()/@import');
        }

        // Paired active elements with their content, then any stray open/close tag.
        $html = self::pcre(preg_replace('#<(script|iframe|frameset|object|applet|portal|fencedframe)\b[^>]*>.*?</\1\s*>#is', '', $html));
        $html = self::pcre(preg_replace('#</?(?:script|iframe|frame|frameset|object|embed|applet|base|portal|fencedframe)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i', '', $html));
        // SECURITY: every <meta> but a plain charset one goes (http-equiv can be
        // entity-encoded or hidden behind a quoted ">"); withCsp() adds its own.
        $html = self::pcre(preg_replace('#<meta\b(?!\s*charset\s*=\s*["\']?[\w-]+["\']?\s*/?>)(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i', '', $html));
        // SECURITY: a tag with unbalanced quotes (x=a'b) evades the quote-aware
        // regexes but is still a tag to the browser — refuse the document.
        $rest = self::pcre(preg_replace('#</?[a-zA-Z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>#', '', $html));
        if (preg_match('#<[a-zA-Z][^>]*>#', $rest)
            || preg_match('#<(?:script|iframe|frame|frameset|object|embed|applet|base|portal|fencedframe)\b|<meta\b(?!\s*charset\s*=\s*["\']?[\w-]+["\']?\s*/?>)#i', $html)) {
            throw new \RuntimeException('PDF: the document carries a malformed or active tag');
        }

        // Attributes, tag by tag (quote-aware, so a ">" inside a value does not end the tag).
        $html = self::pcre(preg_replace_callback(
            '#<([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#',
            static function (array $t) use ($ok): string {
                $isLink = in_array(strtolower($t[1]), ['a', 'area'], true);
                $attrs = (string) preg_replace('#\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $t[2]);
                $attrs = (string) preg_replace_callback(
                    '#(?<=[\s"\'/])(src|srcset|poster|data|background|href|xlink:href)(\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
                    static function (array $a) use ($ok, $isLink): string {
                        $name = strtolower($a[1]);
                        if ($isLink && $name === 'href') {
                            return $a[0];
                        }
                        $value = trim($a[3], '"\'');
                        if ($name === 'srcset') {
                            $urls = array_filter(
                                preg_split('/[\s,]+/', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: [],
                                static fn($p) => $p !== '' && !preg_match('/^\d+(?:\.\d+)?[wx]$/', $p)
                            );
                        } else {
                            $urls = [$value];
                        }
                        foreach ($urls as $u) {
                            if (!$ok($u)) {
                                return $a[1] . $a[2] . '"about:blank"';
                            }
                        }
                        return $a[0];
                    },
                    $attrs
                );
                return '<' . $t[1] . $attrs . '>';
            },
            $html
        ));

        // CSS, wherever it sits (style blocks, style attributes, SVG paint).
        $html = self::pcre(preg_replace_callback(
            '#url\(\s*("[^"]*"|\'[^\']*\'|[^)]*)\s*\)#i',
            static fn(array $m): string => $ok(trim($m[1], " \t\n\r\"'")) ? $m[0] : 'url(about:blank)',
            $html
        ));
        $html = self::pcre(preg_replace_callback(
            '#@import(?:\s|/\*.*?\*/)*("[^"]*"|\'[^\']*\')#is',
            static fn(array $m): string => $ok(trim($m[1], '"\'')) ? $m[0] : '@import "about:blank"',
            $html
        ));
        // image-set() takes bare strings as URLs; no document of ours uses it.
        return self::pcre(preg_replace('#(?:-webkit-)?image-set\s*\(#i', 'blocked-image-set(', $html));
    }

    /** CSS escapes resolved the way the engine's tokenizer does (\72 -> r, \r -> r, escaped newline dropped). */
    private static function cssDecode(string $css): string
    {
        $css = (string) preg_replace('/\\\\(?:\r\n|[\n\r\f])/', '', $css);
        return self::pcre(preg_replace_callback(
            '/\\\\(?:([0-9a-fA-F]{1,6})(?:\r\n|[ \t\r\n\f])?|(.))/s',
            static fn(array $m): string => ($m[1] ?? '') !== ''
                ? ((($c = hexdec($m[1])) > 0 && $c <= 0x10FFFF && ($ch = mb_chr((int) $c, 'UTF-8')) !== false) ? $ch : "\u{FFFD}")
                : $m[2],
            $css
        ));
    }

    /** A PCRE failure (backtrack/JIT limit) must never pass for "sanitised to nothing". */
    private static function pcre(?string $result): string
    {
        if ($result === null) {
            throw new \RuntimeException('PDF: the document could not be sanitised (' . preg_last_error_msg() . ')');
        }
        return $result;
    }

    // ── shared plumbing ──────────────────────────────────────────────────

    /** Writable <project>/tmp/pdf/<engine> dir; null when there is no project or it is not writable. */
    private static function workDir(string $engine): ?string
    {
        if (!defined('_BASE_DIR')) {
            return null;
        }
        $dir = rtrim((string) _BASE_DIR, '/') . '/tmp/pdf/' . $engine;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return null;
        }
        return is_writable($dir) ? $dir : null;
    }

    /**
     * Paper for the CLI engines: PDF_PAGE_SIZE (default Letter) and
     * PDF_MARGINS ("top right bottom left", 1–4 CSS-style values, default
     * "18mm 16mm" — the same @page the documents declare).
     *
     * @return array{0: string, 1: array{0: string, 1: string, 2: string, 3: string}}
     */
    public static function paper(): array
    {
        $size = getenv('PDF_PAGE_SIZE') ?: ($_ENV['PDF_PAGE_SIZE'] ?? 'Letter');
        $m = preg_split('/\s+/', trim((string) (getenv('PDF_MARGINS') ?: ($_ENV['PDF_MARGINS'] ?? '18mm 16mm')))) ?: [];
        $m = array_values(array_filter($m, static fn($v) => $v !== ''));
        $m = match (count($m)) {
            0 => ['18mm', '16mm', '18mm', '16mm'],
            1 => [$m[0], $m[0], $m[0], $m[0]],
            2 => [$m[0], $m[1], $m[0], $m[1]],
            3 => [$m[0], $m[1], $m[2], $m[1]],
            default => [$m[0], $m[1], $m[2], $m[3]],
        };
        return [(string) $size, $m];
    }

    /** Run a CLI engine with a hard timeout; returns its stderr (the PDF is read from disk). */
    private static function run(array $cmd, array $env): string
    {
        $timeout = (int) (getenv('PDF_TIMEOUT') ?: 90);
        static $hasTimeout = null;
        $hasTimeout ??= self::runs('timeout');
        if ($hasTimeout) {
            array_unshift($cmd, 'timeout', (string) $timeout);
        }
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::env($env));
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot start ' . basename((string) $cmd[0]));
        }
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]) . stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return (string) $err;
    }

    // ── dompdf (pure PHP fallback) ───────────────────────────────────────

    private function renderDompdf(string $html): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new \RuntimeException(
                'with_pdf requires dompdf: add "dompdf/dompdf": "^3.1" (or ^2) to the project composer.json and run composer update.'
            );
        }

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        // file:// dropped; http/https gated by the SSRF guard rule below.
        $options->setAllowedProtocols([
            'http://'  => ['rules' => [[self::class, 'assertPublicUrl']]],
            'https://' => ['rules' => [[self::class, 'assertPublicUrl']]],
            // file:// only for the project's own font files: dompdf routes a
            // local registerFont() through the same protocol gate, and
            // nothing outside public/fonts is ever readable this way.
            'file://'  => ['rules' => [[self::class, 'assertProjectFontPath']]],
        ]);

        // Project fonts (public/fonts/**/<Family>-<Style>.ttf) are registered
        // from disk so the PDF embeds the same faces the browser gets via
        // @font-face; dompdf needs a writable font dir for its metrics cache.
        $fontDir = self::fontDir();
        if ($fontDir !== null) {
            $options->set('fontDir', $fontDir);
            $options->set('fontCache', $fontDir);
        }

        $dompdf = new \Dompdf\Dompdf($options);
        if ($fontDir !== null) {
            $families = self::registerProjectFonts($dompdf);
            if ($families !== []) {
                // The document's @font-face rules point at http(s) URLs for
                // browsers; dompdf already has those families from disk, so
                // drop the rules rather than let it re-fetch them over HTTP.
                $html = self::stripFontFace($html, $families);
            }
        }
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * dompdf allowed-protocol rule for file://: only a .ttf that really lives
     * under <project>/public/fonts (realpath, so ../ and symlink tricks fail).
     *
     * @return array{0: bool, 1: string}
     */
    public static function assertProjectFontPath(string $url): array
    {
        if (!defined('_BASE_DIR')) {
            return [false, 'Blocked local resource: no project base'];
        }
        $path = preg_replace('#^file://#', '', $url);
        $real = realpath((string) $path);
        $root = realpath(rtrim((string) _BASE_DIR, '/') . '/public/fonts');
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'ttf') {
            return [false, 'Blocked local resource: not a project font'];
        }
        return [true, ''];
    }

    /** Writable dir for dompdf's font copies + metrics cache; null = keep dompdf defaults. */
    private static function fontDir(): ?string
    {
        if (!defined('_BASE_DIR')) {
            return null;
        }
        $dir = rtrim((string) _BASE_DIR, '/') . '/tmp/dompdf';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return null;
        }
        return is_writable($dir) ? $dir : null;
    }

    /**
     * Register every public/fonts/**\/<Family>-<Style>.ttf (Style: Regular |
     * Bold | Italic | BoldItalic) with dompdf. Returns the family names
     * registered. A font that fails to register is skipped — the PDF then
     * falls back to DejaVu for that family, never fails.
     *
     * @return string[]
     */
    private static function registerProjectFonts(\Dompdf\Dompdf $dompdf): array
    {
        $families = [];
        foreach (self::projectFontFiles(rtrim((string) _BASE_DIR, '/') . '/public/fonts') as $font) {
            try {
                $ok = $dompdf->getFontMetrics()->registerFont(
                    ['family' => $font['family'], 'style' => $font['style'], 'weight' => $font['weight']],
                    $font['path']
                );
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $families[$font['family']] = true;
            }
        }
        return array_keys($families);
    }

    /**
     * Font files under $dir (one level of subdirectories), parsed from their
     * name: "Inter-Bold.ttf" → family Inter, weight bold, style normal.
     *
     * @return array<int, array{path:string, family:string, style:string, weight:string}>
     */
    public static function projectFontFiles(string $dir): array
    {
        static $styles = [
            'regular'     => ['normal', 'normal'],
            'normal'      => ['normal', 'normal'],
            'bold'        => ['normal', 'bold'],
            'italic'      => ['italic', 'normal'],
            'oblique'     => ['italic', 'normal'],
            'bolditalic'  => ['italic', 'bold'],
            'boldoblique' => ['italic', 'bold'],
        ];
        $out = [];
        foreach (array_merge(glob($dir . '/*.ttf') ?: [], glob($dir . '/*/*.ttf') ?: []) as $path) {
            if (!preg_match('/^(.+)-([A-Za-z]+)\.ttf$/', basename($path), $m)) {
                continue;
            }
            $key = strtolower($m[2]);
            if (!isset($styles[$key])) {
                continue;
            }
            [$style, $weight] = $styles[$key];
            $out[] = ['path' => $path, 'family' => $m[1], 'style' => $style, 'weight' => $weight];
        }
        return $out;
    }

    /** Remove @font-face rules whose font-family is one of $families. */
    public static function stripFontFace(string $html, array $families): string
    {
        if ($families === []) {
            return $html;
        }
        $alt = implode('|', array_map(static fn($f) => preg_quote($f, '/'), $families));
        return (string) preg_replace(
            '/@font-face\s*\{[^{}]*font-family\s*:\s*[\'"]?(?:' . $alt . ')[\'"]?\s*;[^{}]*\}/i',
            '',
            $html
        );
    }

    /**
     * dompdf allowed-protocol rule: [$ok, $message]. Rejects any URL whose host
     * resolves to a non-public address, blocking SSRF to internal services and
     * the cloud metadata endpoint. DNS is resolved so a public hostname pointing
     * at a private IP (DNS-rebinding style) is caught too.
     *
     * SECURITY: this is a check-then-fetch — the engine resolves the host again
     * and follows redirects on its own, so a rebinding DNS answer or a 30x to an
     * internal address is not caught here. Run the render engines on a host /
     * network namespace with no route to internal services or the metadata IP.
     *
     * @return array{0: bool, 1: string}
     */
    public static function assertPublicUrl(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            return [false, 'Blocked remote resource: unparseable host'];
        }
        $host = trim($host, '[]'); // strip IPv6 brackets

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
            if (!$ips) {
                return [false, "Blocked remote resource: cannot resolve {$host}"];
            }
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return [false, "Blocked remote resource: non-public address for {$host}"];
            }
        }
        return [true, ''];
    }

    /**
     * True only for globally-routable addresses. Rejects private, loopback,
     * link-local (incl. 169.254.169.254 metadata) and other reserved ranges via
     * the filter flags; also rejects IPv4-mapped/compat IPv6 that would smuggle
     * a private v4 address, which the flags alone don't always catch.
     */
    private static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE)) {
            return false;
        }
        // Unwrap ::ffff:10.0.0.1 / ::10.0.0.1 and re-check the embedded v4.
        if (($packed = @inet_pton($ip)) !== false && strlen($packed) === 16) {
            $v4 = null;
            if (substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
                $v4 = inet_ntop(substr($packed, 12));
            } elseif (substr($packed, 0, 12) === str_repeat("\0", 12) && $packed !== str_repeat("\0", 15) . "\1") {
                $v4 = inet_ntop("\0\0\0\0\0\0\0\0\0\0\xff\xff" . substr($packed, 12));
            }
            if ($v4 !== false && $v4 !== null && strpos($v4, '.') !== false) {
                return (bool) filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE);
            }
        }
        return true;
    }
}
