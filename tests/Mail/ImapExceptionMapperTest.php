<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\Imap\ImapExceptionMapper;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;
use ApiGoat\Sync\Exceptions\ValidationRejected;
use PHPUnit\Framework\TestCase;

class AuthFailedException extends \Exception {}
class ConnectionFailedException extends \Exception {}
class ImapServerErrorException extends \Exception {}
class MessageNotFoundException extends \Exception {}

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
            // guard() wraps EVERY operation, so the gone-check must never win
            // over an auth failure: this is the common wording for a rotated
            // password, and ValidationRejected would permanently fail the job.
            'auth wording with "does not exist"' => [new ImapServerErrorException('NO [AUTHENTICATIONFAILED] User does not exist'), AuthFailed::class],
            // A missing FOLDER is not a gone message — it is a config problem
            // that a human can fix, so the mailbox must keep retrying.
            'missing folder is not a gone message' => [new ImapServerErrorException('Folder does not exist'), TransientError::class],
            'missing mailbox is not a gone message' => [new \RuntimeException('Mailbox does not exist'), TransientError::class],
            'connection'              => [new ConnectionFailedException('connection refused'), TransientError::class],
            'socket timeout'          => [new \RuntimeException('stream_socket_client(): timeout'), TransientError::class],
            'throttled'               => [new ImapServerErrorException('NO [THROTTLED] Too many simultaneous connections'), RateLimited::class],
            'try again'               => [new \RuntimeException('Temporary System Problem. Try again later'), RateLimited::class],
            'overquota'               => [new ImapServerErrorException('NO [OVERQUOTA] mailbox full'), RateLimited::class],
            'no headers found'        => [new \RuntimeException('IMAP fetch body INBOX/38240: no headers found'), ValidationRejected::class],
            'message not found'       => [new \RuntimeException('message not found'), ValidationRejected::class],
            'uid not found'           => [new \RuntimeException('uid 38240 not found'), ValidationRejected::class],
            'nonexistent tag'         => [new ImapServerErrorException('NO [NONEXISTENT] no such message'), ValidationRejected::class],
            'does not exist'          => [new \RuntimeException('the requested message does not exist'), ValidationRejected::class],
            'library not-found class' => [new MessageNotFoundException('gone'), ValidationRejected::class],
        ];
    }

    public function testOurOwnExceptionsPassThroughUntouched(): void
    {
        $e = new RateLimited('x', 30);
        $this->assertSame($e, ImapExceptionMapper::map($e));
    }

    public function testValidationRejectedPassesThroughUntouched(): void
    {
        $e = new ValidationRejected('gone', 404);
        $this->assertSame($e, ImapExceptionMapper::map($e));
    }
}
