<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\Smtp\PhpMailerSmtpTransport;
use ApiGoat\Mail\Smtp\SmtpFailure;
use ApiGoat\Mail\Smtp\SmtpSettings;
use PHPMailer\PHPMailer\SMTP;
use PHPUnit\Framework\TestCase;

/**
 * Drives PHPMailer's REAL SMTP client (wire protocol, DATA / DATA END split,
 * dot-stuffing, RSET) over an in-process stream_socket_pair: the server's
 * replies are pre-buffered on the other end, then that end is closed — a
 * missing reply is read as a dropped connection (EOF), never a 5-min wait.
 * No socket is opened to anything.
 */
final class WireSmtpTest extends TestCase
{
    /** @var resource */
    private $server;

    private ?SMTP $smtp = null;

    /** @param string[] $replies one SMTP reply per line, in order */
    private function transport(array $replies): PhpMailerSmtpTransport
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, implode('', array_map(static fn (string $r): string => $r . "\r\n", $replies)));
        stream_socket_shutdown($server, STREAM_SHUT_WR);
        $this->server = $server;
        $smtp = new class ($client) extends SMTP {
            /** [Timeout, Timelimit] when QUIT is issued */
            public array $atQuit = [];
            public function __construct(private $stream) {}
            protected function getSMTPConnection($host, $port = null, $timeout = 30, $options = []) { return $this->stream; }
            public function quit($close_on_error = true) { $this->atQuit = [$this->Timeout, $this->Timelimit]; return parent::quit($close_on_error); }
        };
        $this->smtp = $smtp;
        return new class ($smtp) extends PhpMailerSmtpTransport {
            public function __construct(private SMTP $smtp) {}
            protected function client(): SMTP { return $this->smtp; }
        };
    }

    private function wire(): string
    {
        stream_set_blocking($this->server, false);
        return (string) stream_get_contents($this->server);
    }

    private function send(array $replies, array $rcpts = ['ada@fx.example'], string $mime = "Subject: x\r\n\r\nbody\r\n.dot line\r\n"): ?SmtpFailure
    {
        try {
            $this->transport($replies)->send(new SmtpSettings('smtp.fx.example', 25, SmtpSettings::NONE, '', ''), 'fred@fx.example', $rcpts, $mime);
        } catch (SmtpFailure $e) {
            return $e;
        }
        return null;
    }

    private const HELLO = ['220 fake ESMTP', '250-fake', '250 8BITMIME'];

    public function testADeliveredMessageIsDotStuffed(): void
    {
        $this->assertNull($this->send([...self::HELLO, '250 ok', '250 ok', '354 go', '250 queued', '221 bye']));
        $wire = $this->wire();
        $this->assertStringContainsString("MAIL FROM:<fred@fx.example>\r\nRCPT TO:<ada@fx.example>\r\nDATA\r\n", $wire);
        $this->assertStringContainsString("\r\n..dot line\r\n", $wire, 'a leading dot is doubled');
        $this->assertMatchesRegularExpression('/\r\n\.\r\nQUIT\r\n$/', $wire, 'terminator, then QUIT');
        $this->assertStringContainsString("QUIT\r\n", $wire);
    }

    public function testNoReplyToTheTerminatorIsUncertain(): void
    {
        $e = $this->send([...self::HELLO, '250 ok', '250 ok', '354 go']);
        $this->assertNotNull($e);
        $this->assertSame(SmtpFailure::PHASE_UNCERTAIN, $e->phase);
        $this->assertTrue($e->mayHaveBeenSent());
        $this->assertStringContainsString("\r\n.\r\n", $this->wire(), 'the body was handed over');
    }

    public function testA451AfterTheBodyIsATransientDataFailure(): void
    {
        $e = $this->send([...self::HELLO, '250 ok', '250 ok', '354 go', '451 try later']);
        $this->assertSame(SmtpFailure::PHASE_DATA, $e->phase);
        $this->assertSame(451, $e->smtpCode);
        $this->assertFalse($e->mayHaveBeenSent());
        $this->assertFalse($e->permanent());
    }

    public function testA554ToTheDataCommandIsPermanentAndNoBodyIsSent(): void
    {
        $e = $this->send([...self::HELLO, '250 ok', '250 ok', '554 no']);
        $this->assertSame(SmtpFailure::PHASE_DATA, $e->phase);
        $this->assertTrue($e->permanent());
        $this->assertStringNotContainsString('Subject: x', $this->wire());
    }

    public function testA550RecipientResetsAndNeverReachesData(): void
    {
        $e = $this->send([...self::HELLO, '250 ok', '550 5.1.1 no such user', '250 reset', '221 bye'], ['bad@fx.example']);
        $this->assertSame(SmtpFailure::PHASE_ENVELOPE, $e->phase);
        $this->assertSame(550, $e->smtpCode);
        $wire = $this->wire();
        $this->assertStringContainsString("RSET\r\n", $wire);
        $this->assertStringNotContainsString("DATA\r\n", $wire);
    }

    public function testAServerThatDoesNotOfferAuthIsAPermanentFailureAndNothingIsSent(): void
    {
        $e = null;
        try {
            $this->transport([...self::HELLO, '221 bye'])->send(new SmtpSettings('smtp.fx.example', 25, SmtpSettings::NONE, 'fred', 'pw'), 'fred@fx.example', ['ada@fx.example'], "Subject: x\r\n\r\nbody\r\n");
        } catch (SmtpFailure $e) {
        }
        $this->assertNotNull($e);
        $this->assertSame(SmtpFailure::PHASE_AUTH, $e->phase);
        $this->assertTrue($e->permanent());
        $wire = $this->wire();
        $this->assertStringNotContainsString('AUTH', $wire);
        $this->assertStringNotContainsString('MAIL FROM', $wire);
        $this->assertStringNotContainsString("DATA\r\n", $wire);
    }

    public function testAClosedConnectionBeforeTheGreetingIsTransient(): void
    {
        $e = $this->send([]);
        $this->assertSame(SmtpFailure::PHASE_CONNECT, $e->phase);
        $this->assertFalse($e->permanent());
    }

    public function testQuitDoesNotInheritTheDataTimeout(): void
    {
        $this->assertNull($this->send([...self::HELLO, '250 ok', '250 ok', '354 go', '250 queued', '221 bye']));
        $this->assertSame([30, 30], $this->smtp->atQuit, 'delivered: back to SmtpSettings::timeout');
        $this->send([...self::HELLO, '250 ok', '250 ok', '354 go']);
        $this->assertSame([30, 30], $this->smtp->atQuit, 'uncertain: back to SmtpSettings::timeout');
    }
}
