<?php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeTokenSource.php';

use ApiGoat\Mail\Connector\GmailConnector;
use ApiGoat\Mail\StateWriter;
use PHPUnit\Framework\TestCase;

final class GmailStateWriterTest extends TestCase
{
    /** @var list<array{method:string,url:string,body:mixed}> */
    private array $calls = [];
    /** @var string[]|null labels of message m1; null = 404 */
    private ?array $labels = ['INBOX', 'UNREAD'];

    private function connector(): GmailConnector
    {
        $http = function (string $method, string $url, array $headers, ?string $body) {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body === null ? null : json_decode($body, true)];
            if ($method === 'GET' && str_contains($url, '/messages/m1?format=minimal')) {
                return $this->labels === null
                    ? ['status' => 404, 'headers' => '', 'body' => '{"error":{"message":"Not Found"}}']
                    : ['status' => 200, 'headers' => '', 'body' => (string) json_encode(['id' => 'm1', 'labelIds' => $this->labels])];
            }
            return ['status' => 200, 'headers' => '', 'body' => '{}'];
        };
        return new GmailConnector(new FakeTokenSource(), $http);
    }

    /** @return list<array{0:string,1:string,2:mixed}> POSTs as [method, path after /messages/, body] */
    private function writes(): array
    {
        $out = [];
        foreach ($this->calls as $c) {
            if ($c['method'] === 'POST') {
                $out[] = ['POST', (string) preg_replace('#^.*/messages/#', '', $c['url']), $c['body']];
            }
        }
        return $out;
    }

    public function testItIsAStateWriter(): void
    {
        $this->assertInstanceOf(StateWriter::class, $this->connector());
    }

    public function testMessageStateMapsLabels(): void
    {
        $this->labels = ['INBOX', 'UNREAD', 'STARRED'];
        $s = $this->connector()->messageState('m1');
        $this->assertFalse($s->seen);
        $this->assertTrue($s->flagged);
        $this->assertSame('INBOX', $s->folder);
        $this->assertSame('Inbox', $s->role);

        $this->labels = ['Label_7'];
        $s = $this->connector()->messageState('m1');
        $this->assertTrue($s->seen);
        $this->assertSame('', $s->folder);
        $this->assertSame('Archive', $s->role);

        $this->assertSame(['TRASH', 'Trash'], GmailConnector::locationOf(['INBOX', 'TRASH']), 'Trash wins over INBOX');
        $this->assertSame(['SPAM', 'Spam'], GmailConnector::locationOf(['SPAM', 'UNREAD']));
    }

    public function testMessageStateIsNullOn404(): void
    {
        $this->labels = null;
        $this->assertNull($this->connector()->messageState('m1'));
    }

    public function testSetFlagStarsAndUnstars(): void
    {
        $c = $this->connector();
        $c->setFlag('m1', true);
        $c->setFlag('m1', false);
        $this->assertSame([
            ['POST', 'm1/modify', ['addLabelIds' => ['STARRED']]],
            ['POST', 'm1/modify', ['removeLabelIds' => ['STARRED']]],
        ], $this->writes());
    }

    public function testArchiveDropsInboxAndKeepsTheId(): void
    {
        $this->assertSame('m1', $this->connector()->archive('m1'));
        $this->assertSame([['POST', 'm1/modify', ['removeLabelIds' => ['INBOX']]]], $this->writes());
    }

    public function testArchiveOfATrashedMessageUntrashesItFirst(): void
    {
        $this->labels = ['TRASH'];
        $this->connector()->archive('m1');
        $this->assertSame([['POST', 'm1/untrash', []]], $this->writes());
    }

    public function testUntrashFromSpamAddsInboxAndDropsSpam(): void
    {
        $this->labels = ['SPAM'];
        $this->assertSame('m1', $this->connector()->untrash('m1'));
        $this->assertSame([['POST', 'm1/modify', ['addLabelIds' => ['INBOX'], 'removeLabelIds' => ['SPAM']]]], $this->writes());
    }

    public function testUntrashFromTrashCallsTheEndpointThenAddsInbox(): void
    {
        $this->labels = ['TRASH'];
        $this->connector()->untrash('m1', 'INBOX');
        $this->assertSame([
            ['POST', 'm1/untrash', []],
            ['POST', 'm1/modify', ['addLabelIds' => ['INBOX']]],
        ], $this->writes());
    }

    public function testEveryMethodRefusesAnUnresolvedIdBeforeAnyApiCall(): void
    {
        $c = $this->connector();
        $calls = [
            'messageState' => fn () => $c->messageState('unresolved:42'),
            'setFlag'      => fn () => $c->setFlag('unresolved:42', true),
            'archive'      => fn () => $c->archive('unresolved:42'),
            'untrash'      => fn () => $c->untrash('unresolved:42'),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("{$name} accepted an unresolved id");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('unresolved', $e->getMessage(), $name);
            }
        }
        $this->assertSame([], $this->calls);
    }
}
