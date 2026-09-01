<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\Imap\ImapExceptionMapper;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;
use PHPUnit\Framework\TestCase;

class AuthFailedException extends \Exception {}
class ConnectionFailedException extends \Exception {}
class ImapServerErrorException extends \Exception {}

final class ImapExceptionMapperTest extends TestCase
{
    /** @dataProvider cases */
    public function testMapping(\Throwable $in, string $expected): void
    {
        $out = ImapExceptionMapper::map($in, 'IMAP connect');
        $this->assertInstanceOf($expected, $out);
        $this->assertSame($in, $out->getPrevious());
        $this->assertStringStartsWith('IMAP connect: ', $out->getMessage());
    }

    public static function cases(): array
    {
        return [
            'library auth class'      => [new AuthFailedException('LOGIN failed'), AuthFailed::class],
            'auth by message'         => [new ImapServerErrorException('NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)'), AuthFailed::class],
            'connection'              => [new ConnectionFailedException('connection refused'), TransientError::class],
            'socket timeout'          => [new \RuntimeException('stream_socket_client(): timeout'), TransientError::class],
            'throttled'               => [new ImapServerErrorException('NO [THROTTLED] Too many simultaneous connections'), RateLimited::class],
            'try again'               => [new \RuntimeException('Temporary System Problem. Try again later'), RateLimited::class],
            'overquota'               => [new ImapServerErrorException('NO [OVERQUOTA] mailbox full'), RateLimited::class],
        ];
    }

    public function testOurOwnExceptionsPassThroughUntouched(): void
    {
        $e = new RateLimited('x', 30);
        $this->assertSame($e, ImapExceptionMapper::map($e));
    }
}
