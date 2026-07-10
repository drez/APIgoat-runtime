<?php

namespace ApiGoat\Pdf;

/**
 * Template-row resolution + placeholder substitution for with_pdf documents.
 *
 * A `template` row carries BOTH the document header (row.body) and the footer
 * (row.footer) plus brand colors (color_1..3) — exactly the shape apigoatacc's
 * "Print Billing Header" rows already use. Resolution order per block:
 *
 *   1. HJSON override name (exact, or `prefix%` LIKE — several matches ⇒
 *      newest Active; an explicit $templateId wins inside the match set)
 *   2. `pdf_<table>`   (per-table seeded/custom row)
 *   3. `pdf_<type>`    (preset row seeded on build)
 *
 * with the record's language preferred and any-language as fallback.
 *
 * Substitution: `{{token}}` strtr vocabulary (parent columns by snake name —
 * escaped + kind-formatted; `{{title}}`, `{{lang}}`, `{{date}}`,
 * `{{config.<key>}}`, `{{color_1..3}}`). For projects that carry the legacy
 * MatchReplace engine (apigoatacc & siblings, `[Entity-Column]` tokens), the
 * resolved HTML additionally runs through it with the record and its FK
 * relations bound — existing template rows render without editing.
 */
final class TemplateRenderer
{
    /** @var array<string,object|null> */
    private array $rows = [];

    /** @var array<int,array{block:string,name:string,lang:string,id_template:int}> */
    private array $used = [];

    /** @var array<string,string> */
    private array $vars = [];

    public function __construct(
        private object $record,
        private array $entry,
        private ?int $templateId = null,
        private ?string $langOverride = null
    ) {
    }

    /** The record's document language ('fr_CA' | 'en_US'); a caller-supplied override wins. */
    public function lang(): string
    {
        if ($this->langOverride !== null && in_array($this->langOverride, ['fr_CA', 'en_US'], true)) {
            return $this->langOverride;
        }
        $src = $this->entry['lang_source'] ?? null;
        if ($src) {
            $getter = 'get' . $src;
            $v = (string) $this->record->$getter();
            if (in_array($v, ['fr_CA', 'en_US'], true)) {
                return $v;
            }
        }
        return 'fr_CA';
    }

    /** Header HTML (resolved row's body column), tokens substituted. */
    public function header(): string
    {
        $row = $this->rowFor('header');
        return $row ? $this->substitute((string) $row->getBody()) : '';
    }

    /** Footer HTML (resolved row's footer column), tokens substituted. */
    public function footer(): string
    {
        $row = $this->rowFor('footer');
        return $row ? $this->substitute((string) $row->getFooter()) : '';
    }

    /**
     * Optional body override: a `templates.body` row whose body column
     * REPLACES the preset middle. Null when no override is configured.
     */
    public function bodyOverride(): ?string
    {
        if (empty(($this->entry['templates'] ?? [])['body'])) {
            return null;
        }
        $row = $this->rowFor('body');
        return $row ? (string) $row->getBody() : null;
    }

    /** color_1..3 from the header row (fallback to a neutral dark green). */
    public function colors(): array
    {
        $row = $this->rowFor('header');
        $c1 = $row ? trim((string) $row->getColor1()) : '';
        $c2 = $row ? trim((string) $row->getColor2()) : '';
        $c3 = $row ? trim((string) $row->getColor3()) : '';
        return [
            'color_1' => $c1 !== '' ? $c1 : '#1f5c34',
            'color_2' => $c2 !== '' ? $c2 : '#313131',
            'color_3' => $c3 !== '' ? $c3 : '#5a5a5a',
        ];
    }

    /** Which template rows fed this document (for the MCP preview). */
    public function templatesUsed(): array
    {
        return array_values($this->used);
    }

    /** The token → value map (for the MCP preview). */
    public function placeholders(): array
    {
        return $this->varsMap();
    }

    /** Substitute {{tokens}} then run the optional legacy MatchReplace pass. */
    public function substitute(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = strtr($html, $this->varsMap());
        return $this->legacyPass($html);
    }

    // ── resolution ─────────────────────────────────────────────────────────

    private function rowFor(string $block): ?object
    {
        if (array_key_exists($block, $this->rows)) {
            return $this->rows[$block];
        }

        $candidates = [];
        $override = ($this->entry['templates'] ?? [])[$block] ?? null;
        if (is_string($override) && $override !== '') {
            $candidates[] = $override;
        }
        $candidates[] = 'pdf_' . (string) ($this->entry['table'] ?? '');
        $candidates[] = 'pdf_' . (string) ($this->entry['type'] ?? 'generic');

        $row = null;
        foreach ($candidates as $name) {
            $row = $this->findRow($name);
            if ($row !== null) {
                break;
            }
        }

        if ($row !== null) {
            $this->used[$block] = [
                'block'       => $block,
                'name'        => (string) $row->getName(),
                'lang'        => (string) $row->getLang(),
                'id_template' => (int) $row->getIdTemplate(),
            ];
        }
        return $this->rows[$block] = $row;
    }

    private function findRow(string $name): ?object
    {
        $q = \App\TemplateQuery::create();
        if (str_contains($name, '%')) {
            $q->filterByName($name); // Propel translates % to LIKE
        } else {
            $q->filterByName($name);
        }
        if ($this->templateId) {
            // Explicit variant pick — honored only when it falls inside the
            // candidate set (never lets a caller pull an arbitrary row).
            $picked = \App\TemplateQuery::create()
                ->filterByName($name)
                ->filterByIdTemplate($this->templateId)
                ->findOne();
            if ($picked) {
                return $picked;
            }
        }

        // Prefer the record's language, newest first; Active rows only when
        // the column is used (status defaults Active in the injected table).
        $rows = $q->orderByDateCreation('DESC')->find();
        $langMatch = null;
        $any = null;
        foreach ($rows as $r) {
            if (method_exists($r, 'getStatus') && (string) $r->getStatus() === 'Inactive') {
                continue;
            }
            if ($any === null) {
                $any = $r;
            }
            if ($langMatch === null && (string) $r->getLang() === $this->lang()) {
                $langMatch = $r;
            }
        }
        return $langMatch ?? $any;
    }

    // ── token map ──────────────────────────────────────────────────────────

    private function varsMap(): array
    {
        if ($this->vars !== []) {
            return $this->vars;
        }
        $lang = $this->lang();
        $ccy  = PdfCurrency::resolve($this->record, $this->entry);
        $map = [
            '{{title}}'    => htmlspecialchars((string) ($this->entry['label'] ?? ''), ENT_QUOTES, 'UTF-8'),
            '{{lang}}'     => $lang,
            '{{date}}'     => \ApiGoat\I18n\Formatter::dateLong(date('Y-m-d'), $lang),
            '{{currency}}' => htmlspecialchars($ccy, ENT_QUOTES, 'UTF-8'),
        ];
        foreach ($this->colors() as $k => $v) {
            $map['{{' . $k . '}}'] = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        }
        foreach ((array) ($this->entry['columns'] ?? []) as $col) {
            $getter = 'get' . $col['php'];
            $map['{{' . $col['snake'] . '}}'] = PresetRenderer::formatValue(
                $this->record->$getter(),
                (string) ($col['kind'] ?? 'text'),
                $lang,
                $ccy
            );
        }
        // {{config.<key>}} — resolved lazily from the config table.
        foreach ($this->configTokens() as $token => $value) {
            $map[$token] = $value;
        }
        return $this->vars = $map;
    }

    private function configTokens(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                $st = \Propel::getConnection()->query('SELECT name, value FROM config');
                foreach ($st as $r) {
                    $cache['{{config.' . $r['name'] . '}}'] =
                        htmlspecialchars((string) $r['value'], ENT_QUOTES, 'UTF-8');
                }
            } catch (\Throwable $e) {
                // No config table — tokens simply don't resolve.
            }
        }
        return $cache;
    }

    // ── legacy MatchReplace pass ───────────────────────────────────────────

    /**
     * Projects like apigoatacc author template rows with `[Entity-Column]`
     * tokens resolved by their own App\Domains\Template\MatchReplace. When
     * that class exists, run the HTML through it with the record (by entity
     * name) and each loaded FK relation bound — full backward compatibility
     * with existing rows, no grammar reimplementation here.
     */
    private function legacyPass(string $html): string
    {
        $mrClass = '\\App\\Domains\\Template\\MatchReplace';
        if (!class_exists($mrClass) || $html === '') {
            return $html;
        }
        try {
            $mr = new $mrClass($html);
            $mr->setDataObj((string) $this->entry['entity'], $this->record);
            foreach ((array) ($this->entry['relations'] ?? []) as $rel) {
                $getter = 'get' . $rel;
                $obj = $this->record->$getter();
                if (is_object($obj)) {
                    $mr->setDataObj($rel, $obj);
                }
            }
            return (string) $mr->getContent();
        } catch (\Throwable $e) {
            return $html; // legacy pass is best-effort; never break the render
        }
    }
}
