<?php

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\EmailPlaceholder;
use ApiGoat\Notify\Mailer;
use ApiGoat\Notify\TemplatedSend;
use PHPUnit\Framework\TestCase;

/**
 * `.invalid` authy.email placeholders (accounts without an email) are "no
 * email" everywhere and are never mailed; skipping one is not a failure.
 */
final class EmailPlaceholderTest extends TestCase
{
    private $prevLog;

    protected function setUp(): void
    {
        $this->prevLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->prevLog);
    }

    public function testIs(): void
    {
        foreach ([null, '', '  ', 'noemail-5@apigtutor.invalid', 'noemail-pending-0123456789abcdef@vidifye.invalid',
                  'deleted-3@deleted.invalid', 'X@Y.INVALID ', ] as $e) {
            self::assertTrue(EmailPlaceholder::is($e), var_export($e, true));
        }
        foreach (['a@example.com', 'a@invalid.com', 'someone@example.invalidx', 'invalid@x.org'] as $e) {
            self::assertFalse(EmailPlaceholder::is($e), $e);
        }
    }

    public function testDisplay(): void
    {
        self::assertSame('', EmailPlaceholder::display('noemail-5@apigtutor.invalid'));
        self::assertSame('', EmailPlaceholder::display(null));
        self::assertSame('a@example.com', EmailPlaceholder::display(' a@example.com '));
    }

    public function testRealRecipients(): void
    {
        self::assertSame(['a@x.com', 'b@y.org'], EmailPlaceholder::realRecipients('a@x.com; noemail-1@p.invalid, b@y.org'));
        self::assertSame(['a@x.com'], EmailPlaceholder::realRecipients(['', 'noemail-1@p.invalid', 'a@x.com', null]));
        self::assertSame([], EmailPlaceholder::realRecipients(''));
    }

    public function testSlugAndForId(): void
    {
        self::assertSame('apigtutor', EmailPlaceholder::slug('apigTutor'));
        self::assertSame('my-app', EmailPlaceholder::slug('My_App'));
        self::assertSame('noemail-12@vidifye.invalid', EmailPlaceholder::forId(12, 'vidifye'));
    }

    public function testMailerAllPlaceholderSendIsASuccessfulNoOp(): void
    {
        // Returns before any PHPMailer / config is touched.
        self::assertTrue(Mailer::send('noemail-5@apigtutor.invalid', 's', '<p>x</p>'));
        self::assertTrue(Mailer::send(['', 'deleted-1@deleted.invalid'], 's', '<p>x</p>', ['bcc' => ['noemail-2@x.invalid']]));
    }

    public function testTemplatedSendFiltersBeforeAnyTransport(): void
    {
        $calls = 0;
        $t = function () use (&$calls) { $calls++; return false; };
        self::assertTrue(TemplatedSend::send(1, 'noemail-5@apigtutor.invalid', [], ['transport' => $t]));
        self::assertSame(0, $calls);
    }

    public function testLegacySendHtmlEmailSkipsPlaceholders(): void
    {
        if (!\function_exists('sendHTMLemail')) {
            require_once __DIR__ . '/../../src/Utility/Legacy/std_function.php';
        }
        self::assertTrue(sendHTMLemail('<p>x</p>', 'from@example.com', 'noemail-5@apigtutor.invalid;deleted-1@deleted.invalid', 's'));
    }
}
