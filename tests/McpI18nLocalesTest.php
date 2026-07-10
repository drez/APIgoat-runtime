<?php
// Run: php vendor/apigoat/runtime/tests/McpI18nLocalesTest.php
// Standalone checks for the MCP i18n surface: locale-tag validation
// (Api::isAllowedI18nLocale), the crm_describe locales block
// (MetaCatalog::localesBlock via build), and the PDF lang override
// (TemplateRenderer::lang).
// MetaCatalog's REL_TYPE const dereferences Propel's RelationMap at class
// initialization; stub it so the catalog can be instantiated without Propel.
if (!class_exists('RelationMap')) {
    class RelationMap { const MANY_TO_ONE = 'MANY_TO_ONE'; const ONE_TO_MANY = 'ONE_TO_MANY'; const MANY_TO_MANY = 'MANY_TO_MANY'; }
}

require __DIR__ . '/../src/ACL/AuthyACL.php';
require __DIR__ . '/../src/Api/Message.php';
require __DIR__ . '/../src/Api/Api.php';
require __DIR__ . '/../src/Api/MetaCatalog.php';
require __DIR__ . '/../src/I18n/LocaleResolver.php';
require __DIR__ . '/../src/Pdf/TemplateRenderer.php';

use ApiGoat\Api\Api;
use ApiGoat\Api\MetaCatalog;
use ApiGoat\Pdf\TemplateRenderer;

function assertEq($a, $b, string $m): void { if ($a !== $b) { fwrite(STDERR, "FAIL: $m (" . json_encode($a) . " !== " . json_encode($b) . ")\n"); exit(1); } }

// ── Api::isAllowedI18nLocale ────────────────────────────────────────────────
// Configured list gates strictly …
assertEq(Api::isAllowedI18nLocale('fr_CA', ['en_US', 'fr_CA']), true, 'supported member passes');
assertEq(Api::isAllowedI18nLocale('de_DE', ['en_US', 'fr_CA']), false, 'non-member rejected');
assertEq(Api::isAllowedI18nLocale('xx', ['en_US', 'fr_CA']), false, 'garbage rejected with config');
// … and without config only a well-formed ll_CC tag passes (never arbitrary
// strings into setLocale()).
assertEq(Api::isAllowedI18nLocale('fr_CA', null), true, 'well-formed tag passes without config');
assertEq(Api::isAllowedI18nLocale('junk!', null), false, 'malformed rejected without config');
assertEq(Api::isAllowedI18nLocale('FR_ca', null), false, 'wrong casing rejected without config');
assertEq(Api::isAllowedI18nLocale("fr_CA'--", null), false, 'suffixed tag rejected without config');

// ── MetaCatalog locales block ───────────────────────────────────────────────
$session = new class {
    public $config = ['locale' => ['supported_locale' => ['en_US', 'fr_CA']]];
    public $authyId = null; // no \App classes standalone → user_language omitted
    public function isAdmin(): bool { return true; }
    public function hasRights($m, $l) { return true; }
};
$catalog = (new MetaCatalog($session))->build([]);
assertEq($catalog['locales']['supported'], ['en_US', 'fr_CA'], 'supported advertised');
assertEq($catalog['locales']['default'], 'fr_CA', 'runtime default preferred when supported');
assertEq(str_contains($catalog['locales']['usage'], "'lang'"), true, 'usage documents the lang argument');
assertEq(array_key_exists('user_language', $catalog['locales']), false, 'user_language omitted when unavailable');

// default_locale config wins when it is a supported member
$session->config = ['locale' => ['supported_locale' => ['en_US', 'fr_CA'], 'default_locale' => 'en_US']];
$catalog = (new MetaCatalog($session))->build([]);
assertEq($catalog['locales']['default'], 'en_US', 'default_locale config wins');

// no locale config → block omitted entirely
$session->config = [];
$catalog = (new MetaCatalog($session))->build([]);
assertEq(array_key_exists('locales', $catalog), false, 'no config → no locales block');

// ── TemplateRenderer lang override ──────────────────────────────────────────
$record = new class { public function getLang(): string { return 'fr_CA'; } };
$entry  = ['lang_source' => 'Lang'];
assertEq((new TemplateRenderer($record, $entry))->lang(), 'fr_CA', 'record language default');
assertEq((new TemplateRenderer($record, $entry, null, 'en_US'))->lang(), 'en_US', 'override wins');
assertEq((new TemplateRenderer($record, $entry, null, 'xx_XX'))->lang(), 'fr_CA', 'unsupported override ignored');
assertEq((new TemplateRenderer($record, [], null, 'en_US'))->lang(), 'en_US', 'override works without lang_source');
assertEq((new TemplateRenderer($record, []))->lang(), 'fr_CA', 'fr_CA fallback without lang_source');

echo "PASS: MCP i18n locales OK\n";
exit(0);
