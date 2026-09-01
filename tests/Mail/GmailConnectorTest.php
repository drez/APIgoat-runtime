<?php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeTokenSource.php';

use ApiGoat\Mail\Connector\GmailConnector;
use ApiGoat\Mail\FetchResult;
use ApiGoat\Mail\HeaderRecord;
use ApiGoat\Mail\MailboxState;
use ApiGoat\Mail\MailConnector;
use ApiGoat\Mail\UnsupportedOperation;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;
use PHPUnit\Framework\TestCase;

final class GmailConnectorTest extends TestCase
{
    /** @var array<int,array{method:string,url:string,headers:string[],body:?string}> */
    private array $calls = [];
    /** @var array<string,array{status:int, body:mixed, headers?:string}|callable> route substring → response */
    private array $routes = [];
    private FakeTokenSource $tokens;

    protected function setUp(): void
    {
        $this->calls  = [];
        $this->routes = [];
        $this->tokens = new FakeTokenSource();
    }

    private function connector(array $opts = []): GmailConnector
    {
        $http = function (string $method, string $url, array $headers, ?string $body) {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            foreach ($this->routes as $needle => $resp) {
                if (str_contains($method . ' ' . $url, $needle)) {
                    $r = is_callable($resp) ? $resp($method, $url, $body) : $resp;
                    if (!is_string($r['body'])) $r['body'] = json_encode($r['body']);
                    return $r + ['headers' => ''];
                }
            }
            return ['status' => 500, 'headers' => '', 'body' => 'no route for ' . $url];
        };
        return new GmailConnector($this->tokens, $http, $opts + ['cold_start_days' => 30]);
    }

    private function msg(string $id, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'threadId' => 't-' . $id, 'labelIds' => ['INBOX', 'UNREAD'], 'snippet' => 'snip ' . $id, 'sizeEstimate' => 1234,
            'internalDate' => '1767225600000',
            'payload' => ['mimeType' => 'multipart/mixed', 'headers' => [
                ['name' => 'From', 'value' => "Sender {$id} <s{$id}@x.com>"],
                ['name' => 'To', 'value' => 'me@x.com'],
                ['name' => 'Subject', 'value' => "Subject {$id}"],
                ['name' => 'Date', 'value' => 'Mon, 31 Aug 2026 18:15:00 -0400'],
                ['name' => 'Message-ID', 'value' => "<{$id}@x>"],
            ], 'parts' => [['mimeType' => 'text/plain', 'filename' => '', 'body' => ['size' => 10]]]],
        ];
    }

    private function urls(string $needle): array
    {
        return array_values(array_map(fn ($c) => $c['url'], array_filter($this->calls, fn ($c) => str_contains($c['url'], $needle))));
    }

    public function testCapabilitiesAndSendUnsupported(): void
    {
        $c = $this->connector();
        $this->assertContains(MailConnector::CAP_THREADS, $c->capabilities());
        $this->assertNotContains(MailConnector::CAP_SEND, $c->capabilities());
        $this->expectException(UnsupportedOperation::class);
        $c->send(['to' => 'a@b', 'subject' => 's']);
    }

    public function testVerifyAndListFolders(): void
    {
        $this->routes['/profile'] = ['status' => 200, 'body' => ['emailAddress' => 'u@x', 'historyId' => '1']];
        $this->routes['/labels']  = ['status' => 200, 'body' => ['labels' => [['id' => 'INBOX', 'name' => 'INBOX', 'type' => 'system'], ['id' => 'Label_1', 'name' => 'Clients', 'type' => 'user']]]];
        $c = $this->connector();
        $c->verify();
        $this->assertSame([['id' => 'INBOX', 'name' => 'INBOX', 'type' => 'system'], ['id' => 'Label_1', 'name' => 'Clients', 'type' => 'user']], $c->listFolders());
        $this->assertSame('Authorization: Bearer tok-1', $this->calls[0]['headers'][0]);
    }

    public function testVerifyWithoutEmailIsAuthFailed(): void
    {
        $this->routes['/profile'] = ['status' => 200, 'body' => []];
        $this->expectException(AuthFailed::class);
        $this->connector()->verify();
    }

    public function testInitialColdStartPinsHistoryFirstBoundsTheQueryAndPagesViaCursor(): void
    {
        $this->routes['/profile']  = ['status' => 200, 'body' => ['emailAddress' => 'u@x', 'historyId' => '5000']];
        $this->routes['/messages?'] = function ($m, $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            return isset($q['pageToken'])
                ? ['status' => 200, 'body' => ['messages' => [['id' => 'c']]]]
                : ['status' => 200, 'body' => ['messages' => [['id' => 'b'], ['id' => 'a']], 'nextPageToken' => 'p2']];
        };
        foreach (['a', 'b', 'c'] as $id) {
            $this->routes["/messages/{$id}?"] = ['status' => 200, 'body' => $this->msg($id)];
        }

        $r = $this->connector()->fetchHeaders('INBOX', null, 2);
        $this->assertTrue($r->coldStart);
        $this->assertSame(FetchResult::REASON_INITIAL, $r->coldStartReason);
        $this->assertFalse($r->complete);
        $this->assertSame(['a', 'b'], array_column($r->headers, 'provider_message_id'), 'newest-first list reversed to oldest-first');
        $this->assertSame('5000', $r->cursor->historyId());
        $this->assertSame('p2', $r->cursor->pageToken());
        $this->assertSame('initial', $r->cursor->get('cold_start'));
        $this->assertStringContainsString('/profile', $this->calls[0]['url'], 'history id pinned BEFORE listing');

        $list = $this->urls('/messages?')[0];
        parse_str((string) parse_url($list, PHP_URL_QUERY), $q);
        $this->assertSame(['labelIds' => 'INBOX', 'q' => 'newer_than:30d', 'maxResults' => '2'], $q);
        $meta = $this->urls('/messages/a?')[0];
        $this->assertStringContainsString('format=metadata', $meta);
        foreach (GmailConnector::METADATA_HEADERS as $h) {
            $this->assertStringContainsString('metadataHeaders=' . $h, $meta);
        }

        // Second call keeps draining the window with the same pinned history id.
        $this->calls = [];
        $r2 = $this->connector()->fetchHeaders('INBOX', $r->cursor, 2);
        $this->assertTrue($r2->complete);
        $this->assertTrue($r2->coldStart);
        $this->assertSame(['c'], array_column($r2->headers, 'provider_message_id'));
        $this->assertSame('5000', $r2->cursor->historyId());
        $this->assertNull($r2->cursor->pageToken());
        $this->assertNull($r2->cursor->get('cold_start'));
        $this->assertSame([], $this->urls('/profile'), 'no second profile call while paging');
        $this->assertStringContainsString('pageToken=p2', $this->urls('/messages?')[0]);
    }

    public function testNormalisedRecordShape(): void
    {
        $h = GmailConnector::normalise($this->msg('a', ['labelIds' => ['INBOX']]), 'INBOX');
        $this->assertSame(HeaderRecord::KEYS, array_keys($h));
        $this->assertSame('a', $h['provider_message_id']);
        $this->assertSame('t-a', $h['thread_id']);
        $this->assertSame('a@x', $h['message_id_header']);
        $this->assertSame('sa@x.com', $h['from_addr']);
        $this->assertSame('Sender a', $h['from_name']);
        $this->assertSame('2026-08-31 22:15:00', $h['date_sent']);
        $this->assertSame('snip a', $h['snippet']);
        $this->assertSame(1234, $h['size_bytes']);
        $this->assertFalse($h['has_attachments']);
        $this->assertTrue($h['was_read_at_fetch'], 'no UNREAD label ⇒ read');
        $this->assertSame(['INBOX'], $h['labels']);
        $this->assertSame('INBOX', $h['folder_at_fetch']);

        $m = $this->msg('b');
        $m['payload']['parts'][] = ['mimeType' => 'application/pdf', 'filename' => 'q.pdf', 'body' => ['attachmentId' => 'att', 'size' => 99]];
        $m['payload']['headers'] = array_values(array_filter($m['payload']['headers'], fn ($x) => $x['name'] !== 'Date'));
        $h = GmailConnector::normalise($m, 'INBOX');
        $this->assertTrue($h['has_attachments']);
        $this->assertFalse($h['was_read_at_fetch']);
        $this->assertSame('2026-01-01 00:00:00', $h['date_sent'], 'internalDate (ms) fallback when Date header missing');
    }

    public function testIncrementalUsesHistoryListAndAdvancesOnlyWhenDrained(): void
    {
        $this->routes['/history?'] = function ($m, $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            return isset($q['pageToken'])
                ? ['status' => 200, 'body' => ['historyId' => '6100', 'history' => [['messagesAdded' => [['message' => ['id' => 'z']]]]]]]
                : ['status' => 200, 'body' => ['historyId' => '6100', 'nextPageToken' => 'h2', 'history' => [
                    ['messagesAdded' => [['message' => ['id' => 'y']], ['message' => ['id' => 'y']]]],
                    ['labelsRemoved' => [['message' => ['id' => 'ignored']]]],
                ]]];
        };
        $this->routes['/messages/y?'] = ['status' => 200, 'body' => $this->msg('y')];
        $this->routes['/messages/z?'] = ['status' => 200, 'body' => $this->msg('z')];

        $r = $this->connector()->fetchHeaders('INBOX', MailboxState::gmail('6000'), 10);
        $this->assertFalse($r->coldStart);
        $this->assertFalse($r->complete);
        $this->assertSame(['y'], array_column($r->headers, 'provider_message_id'), 'deduped; non-messageAdded ignored');
        $this->assertSame('6000', $r->cursor->historyId(), 'watermark unchanged while paging');
        $this->assertSame('h2', $r->cursor->pageToken());
        parse_str((string) parse_url($this->urls('/history?')[0], PHP_URL_QUERY), $q);
        $this->assertSame(['startHistoryId' => '6000', 'labelId' => 'INBOX', 'historyTypes' => 'messageAdded', 'maxResults' => '10'], $q);

        $r2 = $this->connector()->fetchHeaders('INBOX', $r->cursor, 10);
        $this->assertTrue($r2->complete);
        $this->assertSame(['z'], array_column($r2->headers, 'provider_message_id'));
        $this->assertSame('6100', $r2->cursor->historyId());
        $this->assertNull($r2->cursor->pageToken());
    }

    public function testExpiredHistoryIdIsALoudColdStart(): void
    {
        $this->routes['/history?']  = ['status' => 404, 'body' => ['error' => ['message' => 'Requested entity was not found.']]];
        $this->routes['/profile']   = ['status' => 200, 'body' => ['historyId' => '9000']];
        $this->routes['/messages?'] = ['status' => 200, 'body' => ['messages' => [['id' => 'n']]]];
        $this->routes['/messages/n?'] = ['status' => 200, 'body' => $this->msg('n')];

        $r = $this->connector()->fetchHeaders('INBOX', MailboxState::gmail('1'), 10);
        $this->assertTrue($r->coldStart);
        $this->assertSame(FetchResult::REASON_HISTORY_EXPIRED, $r->coldStartReason);
        $this->assertSame('9000', $r->cursor->historyId());
        $this->assertSame(['n'], array_column($r->headers, 'provider_message_id'));
    }

    public function testDeletedMessageBetweenListAndGetIsSkipped(): void
    {
        $this->routes['/history?']    = ['status' => 200, 'body' => ['historyId' => '2', 'history' => [['messagesAdded' => [['message' => ['id' => 'gone']], ['message' => ['id' => 'ok']]]]]]];
        $this->routes['/messages/gone?'] = ['status' => 404, 'body' => '{}'];
        $this->routes['/messages/ok?']   = ['status' => 200, 'body' => $this->msg('ok')];
        $r = $this->connector()->fetchHeaders('INBOX', MailboxState::gmail('1'), 10);
        $this->assertSame(['ok'], array_column($r->headers, 'provider_message_id'));
    }

    public function testUnauthorizedInvalidatesTokenAndRetriesOnceThenAuthFailed(): void
    {
        $n = 0;
        $this->routes['/labels'] = function () use (&$n) {
            $n++;
            return ['status' => 401, 'body' => ['error' => ['message' => 'Invalid Credentials']]];
        };
        try {
            $this->connector()->listFolders();
            $this->fail('should throw');
        } catch (AuthFailed $e) {
            $this->assertSame(2, $n, 'exactly one retry');
            $this->assertSame(1, $this->tokens->invalidated);
            $this->assertSame(2, $this->tokens->issued);
            $this->assertStringContainsString('fake:u@x', $e->getMessage());
        }
    }

    public function testRateLimitAndServerErrorsMapToTheTaxonomy(): void
    {
        $this->routes['/labels'] = ['status' => 429, 'body' => '{}', 'headers' => "Retry-After: 45\r\n"];
        try {
            $this->connector()->listFolders();
            $this->fail('should throw');
        } catch (RateLimited $e) {
            $this->assertSame(45, $e->getCode());
        }
        $this->routes['/labels'] = ['status' => 503, 'body' => 'Backend Error'];
        $this->expectException(TransientError::class);
        $this->connector()->listFolders();
    }

    public function testFetchBodyWalksPartsAndDescribesAttachments(): void
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $this->routes['/messages/m1?format=full'] = ['status' => 200, 'body' => [
            'id' => 'm1', 'sizeEstimate' => 4321,
            'payload' => ['mimeType' => 'multipart/mixed', 'headers' => [['name' => 'Subject', 'value' => 'Hi']], 'parts' => [
                ['mimeType' => 'multipart/alternative', 'parts' => [
                    ['mimeType' => 'text/plain', 'body' => ['data' => $b64('plain text')]],
                    ['mimeType' => 'text/html', 'body' => ['data' => $b64('<p>html <b>text</b></p>')]],
                ]],
                ['mimeType' => 'application/pdf', 'filename' => 'quote.pdf', 'body' => ['attachmentId' => 'ATT1', 'size' => 5000]],
            ]],
        ]];
        $body = $this->connector()->fetchBody('m1');
        $this->assertSame('plain text', $body->text);
        $this->assertSame('<p>html <b>text</b></p>', $body->html);
        $this->assertSame([['filename' => 'quote.pdf', 'mime' => 'application/pdf', 'size' => 5000, 'part_id' => 'ATT1']], $body->attachments);
        $this->assertSame('Hi', $body->headers['subject']);
        $this->assertSame(4321, $body->sizeBytes);
        $this->assertTrue($body->hasAttachments());
    }

    public function testMarkReadMoveAndTrashShapeTheModifyCalls(): void
    {
        $this->routes['/modify'] = ['status' => 200, 'body' => '{}'];
        $this->routes['/trash']  = ['status' => 200, 'body' => '{}'];
        $this->routes['?format=minimal'] = ['status' => 200, 'body' => ['id' => 'm', 'labelIds' => ['INBOX', 'UNREAD', 'Label_3', 'IMPORTANT']]];
        $c = $this->connector();
        $c->markRead('m', true);
        $this->assertSame('{"removeLabelIds":["UNREAD"]}', end($this->calls)['body']);
        $c->markRead('m', false);
        $this->assertSame('{"addLabelIds":["UNREAD"]}', end($this->calls)['body']);
        $this->assertSame('m', $c->move('m', 'Label_9'));
        $this->assertSame('{"addLabelIds":["Label_9"],"removeLabelIds":["INBOX","Label_3"]}', end($this->calls)['body']);
        $this->assertSame('m', $c->trash('m'));
        $this->assertStringEndsWith('/messages/m/trash', end($this->calls)['url']);
        $this->assertSame('POST', end($this->calls)['method']);
    }
}
