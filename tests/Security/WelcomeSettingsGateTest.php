<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Security;

use ApiGoat\Views\WelcomeView;
use PHPUnit\Framework\TestCase;

/**
 * 2026-09-24 review: the dashboard (reachable by every authenticated user)
 * loaded every Config row and printed each value — AI provider keys included —
 * into the Settings pane. The pane now needs Admin/root or an unrestricted
 * Config read right, and secret-like rows are never rendered with a value.
 */
final class WelcomeSettingsGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../src/Views/WelcomeView.php';
        require_once __DIR__ . '/../../src/Ai/AiManifest.php';
    }

    protected function tearDown(): void
    {
        $p = new \ReflectionProperty(\ApiGoat\Ai\AiManifest::class, 'cache');
        $p->setAccessible(true);
        $p->setValue(null, null);
    }

    private static function session(bool $admin, bool $root, $configRight): object
    {
        return new class($admin, $root, $configRight) {
            public function __construct(private bool $admin, private bool $root, private $right) {}
            public function isAdmin() { return $this->admin; }
            public function isRoot() { return $this->root; }
            public function hasRights($m = '', $r = '') { return $m === 'Config' ? $this->right : false; }
        };
    }

    public function testSettingsPaneNeedsAdminRootOrFullConfigRight(): void
    {
        $this->assertTrue(WelcomeView::canSeeSettings(self::session(true, false, false)));
        $this->assertTrue(WelcomeView::canSeeSettings(self::session(false, true, false)));
        $this->assertTrue(WelcomeView::canSeeSettings(self::session(false, false, true)));

        $this->assertFalse(WelcomeView::canSeeSettings(self::session(false, false, false)));
        $this->assertFalse(WelcomeView::canSeeSettings(self::session(false, false, ['Owner'])), 'scoped right is not enough');
        $this->assertFalse(WelcomeView::canSeeSettings(null));
        $this->assertFalse(WelcomeView::canSeeSettings(new \stdClass()));
    }

    public function testSecretLikeConfigKeysAreMasked(): void
    {
        foreach (['openai_api_key', 'anthropic_api_key', 'stripe_secret', 'smtp_password', 'mail_passwd',
                  'gdrive_token', 'API_KEY', 'webhookSecret'] as $k) {
            $this->assertTrue(WelcomeView::isSecretConfigKey($k), $k);
        }
        foreach (['app_status', 'api_ips', 'company_name', 'default_locale'] as $k) {
            $this->assertFalse(WelcomeView::isSecretConfigKey($k), $k);
        }
    }

    public function testAiManifestKeyRowIsMaskedEvenWithAnUnusualName(): void
    {
        $p = new \ReflectionProperty(\ApiGoat\Ai\AiManifest::class, 'cache');
        $p->setAccessible(true);
        $p->setValue(null, ['key_config' => 'llm_credential']);
        $this->assertTrue(WelcomeView::isSecretConfigKey('llm_credential'));
    }

    public function testRenderedSecretInputCarriesNoValueAndTheSaveSkipsEmpty(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Views/WelcomeView.php');
        $this->assertStringContainsString("input('password', 'Value', '',", $src);
        $this->assertStringContainsString("if (this.getAttribute('ag_secret') && this.value === '') { return; }", $src);
    }
}
