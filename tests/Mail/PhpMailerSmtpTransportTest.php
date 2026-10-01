<?php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeSmtp.php';

use ApiGoat\Mail\Smtp\PhpMailerSmtpTransport;
use ApiGoat\Mail\Smtp\SmtpFailure;
use ApiGoat\Mail\Smtp\SmtpSettings;
use PHPUnit\Framework\TestCase;

final class PhpMailerSmtpTransportTest extends TestCase
{
    private FakeSmtp $smtp;

    protected function setUp(): void
    {
        $this->smtp = new FakeSmtp();
    }

    private function send(string $security = SmtpSettings::STARTTLS, array $rcpts = ['ada@fx.example']): void
    {
        $smtp = $this->smtp;
        $t = new class ($smtp) extends PhpMailerSmtpTransport {
            public function __construct(private FakeSmtp $fake) {}
            protected function client(): \PHPMailer\PHPMailer\SMTP { return $this->fake; }
        };
        $t->send(new SmtpSettings('smtp.fx.example', 587, $security, 'fred@fx.example', 'pw'), 'fred@fx.example', $rcpts, "Subject: x\r\n\r\nbody");
    }

    private function failure(callable $fn): SmtpFailure
    {
        try {
            $fn();
        } catch (SmtpFailure $e) {
            return $e;
        }
        $this->fail('expected an SmtpFailure');
    }

    public function testDeliveredOnAnAcceptedDataPhase(): void
    {
        $this->send();
        $names = array_map(static fn ($c) => $c[0], $this->smtp->calls);
        $this->assertSame(['connect', 'hello', 'startTLS', 'hello', 'authenticate', 'mail', 'recipient:ada@fx.example', 'data', 'quit', 'close'], $names);
        $this->assertSame('smtp.fx.example', $this->smtp->calls[0][1], 'STARTTLS connects in clear first');
    }

    public function testImplicitTlsUsesTheSslScheme(): void
    {
        $this->send(SmtpSettings::TLS);
        $this->assertSame('ssl://smtp.fx.example', $this->smtp->calls[0][1]);
        $this->assertNotContains('startTLS', array_map(static fn ($c) => $c[0], $this->smtp->calls));
    }

    public function testAConnectFailureIsTransient(): void
    {
        $this->smtp->answers['connect'] = false;
        $e = $this->failure(fn () => $this->send());
        $this->assertSame(SmtpFailure::PHASE_CONNECT, $e->phase);
        $this->assertFalse($e->permanent());
        $this->assertFalse($e->mayHaveBeenSent());
    }

    public function testRefusedCredentialsArePermanent(): void
    {
        $this->smtp->answers['authenticate'] = false;
        $this->smtp->codes['authenticate'] = 535;
        $e = $this->failure(fn () => $this->send());
        $this->assertSame(SmtpFailure::PHASE_AUTH, $e->phase);
        $this->assertTrue($e->permanent());
    }

    public function testARejectedRecipientIsPermanentAndResetsTheSession(): void
    {
        $this->smtp->answers['recipient:bad@fx.example'] = false;
        $this->smtp->codes['recipient:bad@fx.example'] = 550;
        $e = $this->failure(fn () => $this->send(SmtpSettings::STARTTLS, ['ada@fx.example', 'bad@fx.example']));
        $this->assertSame(SmtpFailure::PHASE_ENVELOPE, $e->phase);
        $this->assertTrue($e->permanent());
        $this->assertStringContainsString('bad@fx.example', $e->getMessage());
        $this->assertContains(['reset'], $this->smtp->calls);
        $this->assertNotContains('data', array_map(static fn ($c) => $c[0], $this->smtp->calls), 'never a partial send');
    }

    public function testA4xxAnswerToDataIsTransientNotUncertain(): void
    {
        $this->smtp->answers['data'] = false;
        $this->smtp->codes['data'] = 451;
        $e = $this->failure(fn () => $this->send());
        $this->assertSame(SmtpFailure::PHASE_DATA, $e->phase);
        $this->assertFalse($e->permanent());
        $this->assertFalse($e->mayHaveBeenSent(), 'the server said no: nothing was accepted');
    }

    public function testNoFinalAnswerToDataIsUncertain(): void
    {
        $this->smtp->answers['data'] = false;   // no code: timeout / dropped connection
        $e = $this->failure(fn () => $this->send());
        $this->assertSame(SmtpFailure::PHASE_UNCERTAIN, $e->phase);
        $this->assertTrue($e->mayHaveBeenSent());
        $this->assertFalse($e->permanent());
    }

    public function testSettingsNeverDumpThePassword(): void
    {
        $s = new SmtpSettings('h', 587, SmtpSettings::STARTTLS, 'u', 'hunter2');
        ob_start();
        var_dump($s);
        $this->assertStringNotContainsString('hunter2', (string) ob_get_clean(), 'var_dump honours __debugInfo()');
        $this->assertSame('hunter2', $s->password());
    }

    /** @return iterable<string,array{0:string,1:string[]}> */
    public static function smuggled(): iterable
    {
        yield 'CRLF in MAIL FROM' => ["fred@fx.example>\r\nRCPT TO:<evil@x.example", ['ada@fx.example']];
        yield 'CRLF in RCPT TO'   => ['fred@fx.example', ["ada@fx.example>\r\nDATA"]];
        yield 'angle in RCPT TO'  => ['fred@fx.example', ['ada@fx.example> NOTIFY=NEVER']];
        yield 'no @'              => ['fred@fx.example', ['ada']];
    }

    /** @dataProvider smuggled */
    public function testSmtpCommandInjectionIsRefusedBeforeConnecting(string $from, array $rcpts): void
    {
        $smtp = $this->smtp;
        $t = new class ($smtp) extends PhpMailerSmtpTransport {
            public function __construct(private FakeSmtp $fake) {}
            protected function client(): \PHPMailer\PHPMailer\SMTP { return $this->fake; }
        };
        try {
            $t->send(new SmtpSettings('h', 587, SmtpSettings::STARTTLS, 'u', 'pw'), $from, $rcpts, "Subject: x\r\n\r\nbody");
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame([], $this->smtp->calls, 'nothing reached the server');
    }

    public function testThePasswordNeverAppearsInAFailure(): void
    {
        $this->smtp->answers['authenticate'] = false;
        $this->smtp->codes['authenticate'] = 535;
        $e = $this->failure(fn () => $this->send());
        $this->assertStringNotContainsString('pw', $e->getMessage());
        $this->assertNotContains('pw', $this->smtp->calls[4], 'the fake records the username only');
        $this->assertSame(0, $this->smtp->do_debug, 'debug output (which would echo AUTH) stays off');
    }

    public function testTlsPeerVerificationIsAlwaysOn(): void
    {
        $this->send(SmtpSettings::TLS);
        $opts = $this->smtp->options;
        $this->assertTrue($opts['ssl']['verify_peer']);
        $this->assertTrue($opts['ssl']['verify_peer_name']);
        $this->assertFalse($opts['ssl']['allow_self_signed']);
    }

    public function testTheFinalReplyToDataGetsAtLeastFiveMinutesOtherPhasesKeepTheSetting(): void
    {
        $this->send();
        $this->assertSame([30, 30], $this->smtp->timeouts['authenticate']);
        $this->assertSame([30, 30], $this->smtp->timeouts['recipient:ada@fx.example']);
        $this->assertSame([300, 300], $this->smtp->timeouts['data'], 'RFC 5321 4.5.3.2.6: up to 10 min for the reply to "."; PHPMailer doubles Timelimit for DATA END');
    }

    public function testAnErrorEscapingTheDataPhaseIsUncertain(): void
    {
        $this->smtp->throws['data'] = new \ErrorException('fwrite(): broken pipe');
        $e = $this->failure(fn () => $this->send());
        $this->assertSame(SmtpFailure::PHASE_UNCERTAIN, $e->phase);
        $this->assertTrue($e->mayHaveBeenSent());
        $this->assertInstanceOf(\ErrorException::class, $e->getPrevious());
    }

    public function testEveryRefusedRecipientIsReportedAndA5xxWins(): void
    {
        $this->smtp->answers['recipient:b1@fx.example'] = false;
        $this->smtp->codes['recipient:b1@fx.example'] = 452;
        $this->smtp->answers['recipient:b2@fx.example'] = false;
        $this->smtp->codes['recipient:b2@fx.example'] = 550;
        $e = $this->failure(fn () => $this->send(SmtpSettings::STARTTLS, ['b1@fx.example', 'ada@fx.example', 'b2@fx.example']));
        $this->assertSame(SmtpFailure::PHASE_ENVELOPE, $e->phase);
        $this->assertSame(550, $e->smtpCode);
        $this->assertTrue($e->permanent());
        $this->assertStringContainsString('b1@fx.example', $e->getMessage());
        $this->assertStringContainsString('b2@fx.example', $e->getMessage());
        $this->assertNotContains('data', array_map(static fn ($c) => $c[0], $this->smtp->calls));
    }

    public function testA4xxRecipientIsTransient(): void
    {
        $this->smtp->answers['recipient:ada@fx.example'] = false;
        $this->smtp->codes['recipient:ada@fx.example'] = 450;
        $e = $this->failure(fn () => $this->send());
        $this->assertFalse($e->permanent());
        $this->assertFalse($e->mayHaveBeenSent());
    }

    public function testA5xxEhloIsAPermanentConnectFailure(): void
    {
        $this->smtp->answers['hello'] = false;
        $this->smtp->codes['hello'] = 554;
        $e = $this->failure(fn () => $this->send());
        $this->assertSame(SmtpFailure::PHASE_CONNECT, $e->phase);
        $this->assertTrue($e->permanent());
    }
}
