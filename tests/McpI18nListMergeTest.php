<?php
// Run: php vendor/apigoat/runtime/tests/McpI18nListMergeTest.php
// Standalone check of AbstractCrmTool::mergeI18nColumnsIntoRows — the batched
// crm_list i18n merge — against fake \App Propel classes (no DB): one IN()
// query for the page, per-row locale resolution (lang → row lang → user
// fallback → fr_CA), empty-value fr_CA fallback, snake keys, guard paths.

namespace ApiGoat\Sessions { if (!class_exists(AuthySession::class)) { class AuthySession {} } }

namespace {
    if (!class_exists('Criteria')) { class Criteria { const IN = 'IN'; } }

    require __DIR__ . '/../src/Mcp/McpTool.php';
    require __DIR__ . '/../src/Mcp/ToolError.php';
    require __DIR__ . '/../src/Mcp/Tools/AbstractCrmTool.php';

    function assertEq($a, $b, string $m): void { if ($a !== $b) { fwrite(STDERR, "FAIL: $m (" . json_encode($a) . " !== " . json_encode($b) . ")\n"); exit(1); } }
}

namespace App {
    // Fake translation row: quote_i18n shape (IdQuote, Locale, Terms, Notes).
    // Concrete getters only — the merge must NOT rely on getByName (the
    // emitted models keep it but drop its getByPosition delegate → fatal).
    class FakeI18nRow
    {
        public function __construct(private array $vals) {}
        public function getIdQuote() { return $this->vals['IdQuote'] ?? null; }
        public function getLocale() { return $this->vals['Locale'] ?? null; }
        public function getTerms() { return $this->vals['Terms'] ?? null; }
        public function getNotes() { return $this->vals['Notes'] ?? null; }
        public function getDateCreation() { return $this->vals['DateCreation'] ?? null; }
        public function getByName(string $n): void { throw new \RuntimeException('getByName must not be used (no getByPosition on emitted models)'); }
    }

    class QuotePeer
    {
        // Main-table fields: tablestamps exist on BOTH tables and must never
        // be sourced from the i18n row.
        public static function getFieldNames(): array { return ['IdQuote', 'QuoteNumber', 'DateCreation']; }
    }

    class QuoteI18nPeer
    {
        public static function getFieldNames(): array { return ['IdQuote', 'Locale', 'Terms', 'Notes', 'DateCreation']; }
    }

    class QuoteI18nQuery
    {
        public static array $lastFilter = [];
        public static array $rows = [];
        public static function create(): self { return new self(); }
        public function filterBy(string $col, $val, $cmp = null): self { self::$lastFilter = [$col, $val, $cmp]; return $this; }
        public function find(): array
        {
            $ids = self::$lastFilter[1] ?? [];
            return array_values(array_filter(self::$rows, fn ($r) => in_array($r->getIdQuote(), $ids, true)));
        }
    }
}

namespace {
    use App\FakeI18nRow;
    use App\QuoteI18nQuery;

    $tool = new class extends \ApiGoat\Mcp\Tools\AbstractCrmTool {
        public function name(): string { return 't'; }
        public function description(): string { return ''; }
        public function inputSchema(): array { return []; }
        public function handle(array $args, \ApiGoat\Sessions\AuthySession $session): array { return []; }
        public function merge(string $entity, $data, ?string $lang, ?string $fallback = null)
        {
            return $this->mergeI18nColumnsIntoRows($entity, $data, $lang, $fallback);
        }
    };

    QuoteI18nQuery::$rows = [
        new FakeI18nRow(['IdQuote' => 1, 'Locale' => 'fr_CA', 'Terms' => 'Net 30 jours', 'Notes' => 'Merci']),
        new FakeI18nRow(['IdQuote' => 1, 'Locale' => 'en_US', 'Terms' => 'Net 30 days', 'Notes' => '']),
        new FakeI18nRow(['IdQuote' => 2, 'Locale' => 'fr_CA', 'Terms' => 'Payable sur réception', 'Notes' => '']),
    ];

    // Explicit lang wins; empty en_US value falls back to fr_CA; missing
    // translation rows resolve to fr_CA content.
    $rows = $tool->merge('Quote', [
        ['id_quote' => 1, 'lang' => 'fr_CA'],
        ['id_quote' => 2, 'lang' => 'fr_CA'],
    ], 'en_US');
    assertEq($rows[0]['terms'], 'Net 30 days', 'explicit lang wins over row lang');
    assertEq($rows[0]['notes'], 'Merci', 'empty value falls back to fr_CA');
    assertEq($rows[1]['terms'], 'Payable sur réception', 'missing locale row falls back to fr_CA');
    assertEq(QuoteI18nQuery::$lastFilter[0], 'IdQuote', 'batched on the i18n FK');
    assertEq(QuoteI18nQuery::$lastFilter[1], [1, 2], 'single IN() over the page ids');

    // No lang arg → the row's own lang column drives each row independently.
    $rows = $tool->merge('Quote', [
        ['id_quote' => 1, 'lang' => 'en_US'],
        ['id_quote' => 2, 'lang' => 'fr_CA'],
    ], null);
    assertEq($rows[0]['terms'], 'Net 30 days', 'row lang en_US');
    assertEq($rows[1]['terms'], 'Payable sur réception', 'row lang fr_CA');

    // No lang, no row lang → user-language fallback; else fr_CA.
    $rows = $tool->merge('Quote', [['id_quote' => 1]], null, 'en_US');
    assertEq($rows[0]['terms'], 'Net 30 days', 'user-language fallback');
    $rows = $tool->merge('Quote', [['id_quote' => 1]], null, null);
    assertEq($rows[0]['terms'], 'Net 30 jours', 'fr_CA default');

    // Existing row keys are never clobbered.
    $rows = $tool->merge('Quote', [['id_quote' => 1, 'terms' => 'already selected']], 'en_US');
    assertEq($rows[0]['terms'], 'already selected', 'existing key preserved');

    // Columns shared with the main table (tablestamps) are never merged from
    // the i18n row — even when the projected row lacks them.
    $rows = $tool->merge('Quote', [['id_quote' => 1]], 'en_US');
    assertEq(array_key_exists('date_creation', $rows[0]), false, 'main-table column not sourced from i18n row');

    // Guard paths: rows without the pk, non-list shapes, unknown entities.
    $noPk = [['name' => 'projected row']];
    assertEq($tool->merge('Quote', $noPk, 'en_US'), $noPk, 'rows without pk untouched');
    assertEq($tool->merge('Quote', [], 'en_US'), [], 'empty data untouched');
    assertEq($tool->merge('Quote', ['scalar'], 'en_US'), ['scalar'], 'non-assoc rows untouched');
    $single = ['id_quote' => 1, 'x' => 'single assoc row (crm_get shape)'];
    assertEq($tool->merge('Quote', $single, 'en_US'), $single, 'single row untouched (crm_get path owns it)');
    assertEq($tool->merge('Nope', [['id_nope' => 1]], 'en_US'), [['id_nope' => 1]], 'entity without i18n classes untouched');

    echo "PASS: MCP i18n list merge OK\n";
    exit(0);
}
