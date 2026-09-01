<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\BaseConnector;
use ApiGoat\Mail\FetchResult;
use ApiGoat\Mail\MailBody;
use ApiGoat\Mail\MailboxState;
use ApiGoat\Mail\MailConnector;
use ApiGoat\Mail\UnsupportedOperation;
use PHPUnit\Framework\TestCase;

final class BaseConnectorTest extends TestCase
{
    private function minimal(): MailConnector
    {
        return new class extends BaseConnector {
            public function verify(): void {}
            public function listFolders(): array { return []; }
            public function fetchHeaders(string $folder, ?MailboxState $cursor, int $max): FetchResult
            {
                return new FetchResult([], new MailboxState([]), true);
            }
            public function fetchBody(string $providerId): MailBody { return new MailBody($providerId, '', ''); }
        };
    }

    /** @dataProvider phase23 */
    public function testPhase2And3MethodsThrowUnsupportedByDefault(string $method, array $args): void
    {
        $c = $this->minimal();
        $this->assertNotContains(MailConnector::CAP_SEND, $c->capabilities());
        $this->assertSame([MailConnector::CAP_LIST_FOLDERS, MailConnector::CAP_FETCH_BODY], $c->capabilities());
        try {
            $c->$method(...$args);
            $this->fail("$method should throw");
        } catch (UnsupportedOperation $e) {
            $this->assertStringContainsString("does not support {$method}()", $e->getMessage());
            $this->assertInstanceOf(\LogicException::class, $e, 'never retried by the queue');
        }
    }

    public static function phase23(): array
    {
        return [
            ['markRead', ['1', true]],
            ['move', ['1', 'Archive']],
            ['trash', ['1']],
            ['send', [['to' => 'a@b', 'subject' => 's']]],
        ];
    }

    public function testFetchResultDefaults(): void
    {
        $r = new FetchResult([['x' => 1]], MailboxState::gmail('1'));
        $this->assertTrue($r->complete);
        $this->assertFalse($r->coldStart);
        $this->assertNull($r->coldStartReason);
        $this->assertSame(1, $r->count());
    }
}
