<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Notify;

require_once __DIR__ . '/../../src/Notify/Mailer.php';

use ApiGoat\Notify\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * SECURITY: STARTTLS auto-upgrade must stay on by default so SMTP AUTH
 * credentials are not sent in cleartext when smtp_secure is unset.
 */
final class MailerAutoTlsTest extends TestCase
{
    public function testAutoTlsOnByDefault(): void
    {
        $this->assertTrue(Mailer::autoTls([]));
        $this->assertTrue(Mailer::autoTls(['host' => 'smtp.example.com', 'smtp_secure' => '']));
    }

    public function testOnlyExplicitFalseOptsOut(): void
    {
        $this->assertFalse(Mailer::autoTls(['smtp_autotls' => false]));
        $this->assertTrue(Mailer::autoTls(['smtp_autotls' => 0]));
        $this->assertTrue(Mailer::autoTls(['smtp_autotls' => null]));
    }
}
