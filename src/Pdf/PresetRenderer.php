<?php

namespace ApiGoat\Pdf;

use ApiGoat\I18n\Formatter;

/**
 * Assembles the printable HTML for a with_pdf record:
 *
 *   custom  → the project class (PdfHtmlRendererInterface) returns the FULL
 *             document; nothing is added around it.
 *   others  → css(colors) + template header + MIDDLE + template footer,
 *             where MIDDLE is:
 *               quote/invoice → items table (lines child) + details section
 *                               + totals block
 *               generic       → key/value table of the parent's columns
 *                               (+ details when declared)
 *             or the `templates.body` row when a body override is configured
 *             ({{items}}/{{details}}/{{totals}}/{{fields}} tokens inside it
 *             are replaced by the generated sections).
 *
 * Section markup/CSS generalizes apicrm's SectionBlocks (.gc-doc layout);
 * brand colors come from the template row (color_1..3).
 */
final class PresetRenderer
{
    /**
     * @return array{html:string, templates_used:array, placeholders:array, lang:string}
     */
    public static function render(object $record, array $entry, ?int $templateId = null): array
    {
        if (($entry['type'] ?? '') === 'custom') {
            $class = (string) ($entry['class'] ?? '');
            if ($class === '' || !class_exists($class)) {
                throw new \RuntimeException("with_pdf custom renderer '{$class}' not found");
            }
            $renderer = new $class();
            if (!$renderer instanceof PdfHtmlRendererInterface) {
                throw new \RuntimeException("with_pdf custom renderer '{$class}' must implement " . PdfHtmlRendererInterface::class);
            }
            return [
                'html'           => $renderer->render($record),
                'templates_used' => [],
                'placeholders'   => [],
                'lang'           => 'fr_CA',
            ];
        }

        $tpl  = new TemplateRenderer($record, $entry, $templateId);
        $lang = $tpl->lang();
        $ccy  = PdfCurrency::resolve($record, $entry);

        $sections = [
            '{{items}}'   => self::itemsTable($record, $entry, $lang, $ccy),
            '{{details}}' => self::detailsSection($record, $entry, $lang),
            '{{totals}}'  => self::totalsBlock($record, $entry, $lang, $ccy),
            '{{fields}}'  => self::fieldsTable($record, $entry, $lang, $ccy),
        ];

        $override = $tpl->bodyOverride();
        if ($override !== null) {
            $middle = $tpl->substitute(strtr($override, $sections));
        } elseif (($entry['type'] ?? 'generic') === 'generic') {
            $middle = $sections['{{fields}}'] . $sections['{{details}}'];
        } else { // quote | invoice
            $middle = $sections['{{items}}'] . $sections['{{details}}'] . $sections['{{totals}}'];
        }

        $html = self::css($tpl->colors())
            . '<div class="gc-doc">'
            . $tpl->header()
            . $middle
            . $tpl->footer()
            . '</div>';

        return [
            'html'           => $html,
            'templates_used' => $tpl->templatesUsed(),
            'placeholders'   => $tpl->placeholders(),
            'lang'           => $lang,
        ];
    }

    // ── sections ───────────────────────────────────────────────────────────

    private static function itemsTable(object $record, array $entry, string $lang, string $ccy = PdfCurrency::FALLBACK): string
    {
        $lines = $entry['lines'] ?? null;
        if (!$lines) {
            return '';
        }
        $rows = self::childRows($record, $entry, $lines);
        if ($rows === []) {
            return '';
        }

        $cols = (array) ($lines['columns'] ?? []);
        $th = '';
        foreach ($cols as $c) {
            $num = in_array($c['kind'] ?? 'text', ['num', 'money'], true) ? ' class="num"' : '';
            $th .= '<th' . $num . '>' . self::e($c['label'] ?? $c['snake']) . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($cols as $c) {
                $getter = 'get' . $c['php'];
                $num = in_array($c['kind'] ?? 'text', ['num', 'money'], true) ? ' class="num"' : '';
                $body .= '<td' . $num . '>' . self::formatValue($row->$getter(), (string) ($c['kind'] ?? 'text'), $lang, $ccy) . '</td>';
            }
            $body .= '</tr>';
        }
        return '<table class="items"><tr>' . $th . '</tr>' . $body . '</table>';
    }

    private static function detailsSection(object $record, array $entry, string $lang): string
    {
        $details = $entry['details'] ?? null;
        if (!$details) {
            return '';
        }
        $rows = self::childRows($record, $entry, $details);
        $out = '';
        foreach ($rows as $row) {
            $nameGetter  = 'get' . $details['name_php'];
            $valueGetter = 'get' . $details['value_php'];
            $value = trim((string) $row->$valueGetter());
            if ($value === '') {
                continue;
            }
            $out .= '<tr><td class="lbl">' . self::e((string) $row->$nameGetter())
                . '</td><td>' . self::e($value) . '</td></tr>';
        }
        return $out === '' ? '' : '<table class="kv">' . $out . '</table>';
    }

    private static function totalsBlock(object $record, array $entry, string $lang, string $ccy = PdfCurrency::FALLBACK): string
    {
        $totals = (array) ($entry['totals'] ?? []);
        if ($totals === []) {
            return '';
        }
        $rows = '';
        $last = count($totals) - 1;
        foreach ($totals as $i => $t) {
            $getter = 'get' . $t['php'];
            $cls = $i === $last ? ' class="grand"' : '';
            $rows .= '<tr' . $cls . '><td>' . self::e($t['label'] ?? $t['php']) . '</td>'
                . '<td class="num">' . self::formatValue($record->$getter(), (string) ($t['kind'] ?? 'money'), $lang, $ccy) . '</td></tr>';
        }
        return '<table class="totals">' . $rows . '</table>';
    }

    private static function fieldsTable(object $record, array $entry, string $lang, string $ccy = PdfCurrency::FALLBACK): string
    {
        $rows = '';
        foreach ((array) ($entry['columns'] ?? []) as $c) {
            $getter = 'get' . $c['php'];
            $value = self::formatValue($record->$getter(), (string) ($c['kind'] ?? 'text'), $lang, $ccy);
            if (trim($value) === '') {
                continue;
            }
            $rows .= '<tr><td class="lbl">' . self::e($c['label'] ?? $c['snake']) . '</td><td>' . $value . '</td></tr>';
        }
        return $rows === '' ? '' : '<table class="kv">' . $rows . '</table>';
    }

    /** @return object[] */
    private static function childRows(object $record, array $entry, array $child): array
    {
        $queryClass = '\\App\\' . $child['entity'] . 'Query';
        if (!class_exists($queryClass)) {
            return [];
        }
        $pkGetter = 'get' . $entry['pk_php'];
        $filter   = 'filterBy' . $child['fk_php'];
        $rows = $queryClass::create()->$filter($record->$pkGetter())->find();
        return iterator_to_array($rows, false);
    }

    // ── formatting ─────────────────────────────────────────────────────────

    /** Escape + kind-format a value (money/date via the shared Formatter). */
    public static function formatValue(mixed $value, string $kind, string $lang, string $currency = PdfCurrency::FALLBACK): string
    {
        if ($value instanceof \DateTime) {
            $value = $value->format('Y-m-d');
        }
        $s = (string) ($value ?? '');
        if ($s === '') {
            return '';
        }
        return match ($kind) {
            'money' => self::e(Formatter::money($s, $currency, $lang)),
            'date'  => self::e(Formatter::dateLong(substr($s, 0, 10), $lang)),
            default => self::e($s),
        };
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** .gc-doc print CSS (SectionBlocks port; brand colors from the template row). */
    public static function css(array $colors): string
    {
        $c1 = $colors['color_1'];
        $c2 = $colors['color_2'];
        $c3 = $colors['color_3'];
        return <<<CSS
<style>
  .gc-doc { font-family: "DejaVu Sans", sans-serif; color: #1a1a1a; font-size: 11px; }
  .gc-doc .kicker { font-size: 22px; font-weight: bold; letter-spacing: 2px; color: {$c1}; margin: 0 0 2px; }
  .gc-doc h1 { font-size: 14px; margin: 0 0 14px; color: {$c2}; }
  .gc-doc h2.sec { font-size: 11px; text-transform: uppercase; background: {$c1}; color: #fff; padding: 4px 8px; margin: 18px 0 8px; }
  .gc-doc table.kv { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  .gc-doc table.kv td { padding: 3px 8px; border-bottom: 1px solid #e3e3e3; }
  .gc-doc table.kv td.lbl { width: 40%; color: {$c3}; }
  .gc-doc table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  .gc-doc table.items th { text-align: left; border-bottom: 2px solid {$c1}; padding: 6px 8px; font-size: 10px; text-transform: uppercase; }
  .gc-doc table.items td { padding: 6px 8px; border-bottom: 1px solid #ddd; }
  .gc-doc td.num, .gc-doc th.num { text-align: right; white-space: nowrap; }
  .gc-doc table.totals { width: 55%; margin-left: 45%; border-collapse: collapse; margin-bottom: 12px; }
  .gc-doc table.totals td { padding: 4px 8px; }
  .gc-doc table.totals tr.grand td { border-top: 2px solid {$c1}; font-weight: bold; font-size: 12px; }
</style>
CSS;
    }
}
