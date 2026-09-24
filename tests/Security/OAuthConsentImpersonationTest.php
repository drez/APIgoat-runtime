<?php

namespace ApiGoat\Tests\Security;

use ApiGoat\Services\OAuthAuthorizeService;
use ApiGoat\Sessions\AuthySession;
use PHPUnit\Framework\TestCase;

/**
 * Review-3 Wave 3: OAuth consent is refused while a root impersonates another
 * user (iarc switch), so the impersonator cannot mint a long-lived token for
 * the target's account. Both the GET render and the POST decision consult
 * isImpersonating().
 */
final class OAuthConsentImpersonationTest extends TestCase
{
    public function testPlainSessionIsNotImpersonating(): void
    {
        $s = new AuthySession();
        $s->set('id', 4);
        self::assertFalse(OAuthAuthorizeService::isImpersonating($s));
        self::assertFalse(OAuthAuthorizeService::isImpersonating(null));
    }

    public function testSwitchedSessionIsImpersonating(): void
    {
        $s = new AuthySession();
        $s->set('id', 4);
        $s->sessVar['ImpersonatorId'] = 1;
        self::assertTrue(OAuthAuthorizeService::isImpersonating($s));
    }

    public function testBothConsentPathsAreGuarded(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Services/OAuthAuthorizeService.php');
        // POST decision + GET render each check before reaching consent.
        self::assertSame(2, substr_count($src, 'if (self::isImpersonating($session))'));
        self::assertMatchesRegularExpression(
            '/isImpersonating\(\$session\)\)\s*\{\s*return \$this->renderLogin[^;]+;\s*\}\s*return \$this->renderConsent/',
            $src
        );
        // a fresh sign-in lifts it
        self::assertStringContainsString("unset(\$s->sessVar['ImpersonatorId']", $src);
    }
}
