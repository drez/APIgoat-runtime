<?php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeTokenSource.php';

use ApiGoat\Mail\Connector\GraphConnector;
use ApiGoat\Mail\FetchResult;
use ApiGoat\Mail\MailboxState;
use ApiGoat\Mail\MailConnector;
use PHPUnit\Framework\TestCase;

final class GraphConnectorTest extends TestCase
{
    private const DELTA = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=';

    /** @var array<int,array{method:string,url:string,headers:string[],body:?string}> */
    private array $calls = [];
    /** @var array<string,array{status:int, body:mixed, headers?:string}> route substring → response */
    private array $routes = [];
    private FakeTokenSource $tokens;

    protected function setUp(): void
    {
        $this->calls  = [];
        $this->routes = [];
        $this->tokens = new FakeTokenSource();
    }

    private function http(): \Closure
    {
        return function (string $method, string $url, array $headers, ?string $body) {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            foreach ($this->routes as $needle => $resp) {
                if (str_contains($method . ' ' . $url, (string) $needle)) {
                    if (!is_string($resp['body'])) $resp['body'] = json_encode($resp['body']);
                    return $resp + ['headers' => ''];
                }
            }
            return ['status' => 500, 'headers' => '', 'body' => 'no route for ' . $url];
        };
    }

    private function connector(array $opts = []): GraphConnector
    {
        return new GraphConnector($this->tokens, '/me', $this->http(), $opts);
    }

    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/graph/' . $name), true);
    }

    public function test_capabilities(): void
    {
        $caps = $this->connector()->capabilities();
        foreach ([MailConnector::CAP_LIST_FOLDERS, MailConnector::CAP_FETCH_BODY, MailConnector::CAP_MARK_READ, MailConnector::CAP_MOVE, MailConnector::CAP_TRASH, MailConnector::CAP_SEND, MailConnector::CAP_BACKFILL] as $c) {
            $this->assertContains($c, $caps);
        }
    }

    public function test_cold_start_filters_on_received_date_and_stores_the_deltaLink(): void
    {
        $this->routes['/mailFolders/inbox/messages/delta'] = ['status' => 200, 'body' => [
            'value' => [['id' => 'AAA1']], '@odata.deltaLink' => self::DELTA . 'D1']];
        $this->routes['GET https://graph.microsoft.com/v1.0/me/messages/AAA1?'] = ['status' => 200, 'body' => $this->fixture('message1.json')];
        $r = $this->connector()->fetchHeaders('inbox', null, 50);
        $this->assertTrue($r->coldStart);
        $this->assertSame(FetchResult::REASON_INITIAL, $r->coldStartReason);
        $this->assertStringContainsString('receivedDateTime+ge+', $this->calls[0]['url']);
        $this->assertContains('Prefer: odata.maxpagesize=50', $this->calls[0]['headers']);
        $this->assertSame(self::DELTA . 'D1', $r->cursor->deltaLink());
        $this->assertSame('AAA1', $r->headers[0]['provider_message_id']);
        $this->assertSame('m1@x.com', $r->headers[0]['message_id_header'], 'HeaderRecord strips the angle brackets');
        $this->assertSame('pass', substr((string) $r->headers[0]['auth_results'], -4));
        $this->assertSame('inbox', $r->headers[0]['folder_at_fetch']);
        $this->assertSame('C1', $r->headers[0]['thread_id']);
        $this->assertSame('ann@x.com', $r->headers[0]['from_addr']);
        $this->assertSame(['me@y.com'], $r->headers[0]['to']);
        $this->assertSame('<news.x.com>', $r->headers[0]['list_id']);
        $this->assertFalse($r->headers[0]['was_read_at_fetch']);
        $this->assertSame([], $r->headers[0]['labels']);
    }

    public function test_incremental_follows_the_stored_deltaLink(): void
    {
        $this->routes['$deltatoken=D1'] = ['status' => 200, 'body' => ['value' => [], '@odata.deltaLink' => self::DELTA . 'D2']];
        $r = $this->connector()->fetchHeaders('inbox', MailboxState::graph(self::DELTA . 'D1', 'inbox'), 50);
        $this->assertFalse($r->coldStart);
        $this->assertTrue($r->complete);
        $this->assertStringEndsWith('D2', $r->cursor->deltaLink());
        $this->assertSame(self::DELTA . 'D1', $this->calls[0]['url']);
        $this->assertContains('Prefer: odata.maxpagesize=50', $this->calls[0]['headers']);
    }

    public function test_partial_delta_keeps_the_nextLink_as_cursor_and_is_incomplete(): void
    {
        $this->routes['$deltatoken=D1'] = ['status' => 200, 'body' => ['value' => [['id' => 'AAA1']], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$skiptoken=S1']];
        $this->routes['/me/messages/AAA1?'] = ['status' => 200, 'body' => $this->fixture('message1.json')];
        $r = $this->connector()->fetchHeaders('inbox', MailboxState::graph(self::DELTA . 'D1', 'inbox'), 1);
        $this->assertFalse($r->complete);
        $this->assertStringEndsWith('S1', $r->cursor->nextLink());
        $this->assertStringEndsWith('D1', $r->cursor->deltaLink(), 'the watermark only moves when paging reached a deltaLink');
        // next call follows the nextLink, keeping maxpagesize
        $this->routes = ['$skiptoken=S1' => ['status' => 200, 'body' => ['value' => [], '@odata.deltaLink' => self::DELTA . 'D2']]];
        $this->calls = [];
        $r2 = $this->connector()->fetchHeaders('inbox', $r->cursor, 1);
        $this->assertTrue($r2->complete);
        $this->assertStringEndsWith('D2', $r2->cursor->deltaLink());
        $this->assertNull($r2->cursor->nextLink());
        $this->assertContains('Prefer: odata.maxpagesize=1', $this->calls[0]['headers']);
    }

    public function test_pages_are_followed_until_max_or_deltaLink(): void
    {
        $this->routes['$skiptoken=S1'] = ['status' => 200, 'body' => ['value' => [['id' => 'AAA1']], '@odata.deltaLink' => self::DELTA . 'D3']];
        $this->routes['$deltatoken=D1'] = ['status' => 200, 'body' => ['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$skiptoken=S1']];
        $this->routes['/me/messages/AAA1?'] = ['status' => 200, 'body' => $this->fixture('message1.json')];
        $r = $this->connector()->fetchHeaders('inbox', MailboxState::graph(self::DELTA . 'D1', 'inbox'), 50);
        $this->assertTrue($r->complete);
        $this->assertCount(1, $r->headers);
        $this->assertStringEndsWith('D3', $r->cursor->deltaLink());
    }

    public function test_cold_start_paging_keeps_cold_start_reason_in_the_cursor(): void
    {
        $this->routes['messages/delta?$filter'] = ['status' => 200, 'body' => ['value' => [['id' => 'AAA1']], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$skiptoken=C1']];
        $this->routes['/me/messages/AAA1?'] = ['status' => 200, 'body' => $this->fixture('message1.json')];
        $r = $this->connector()->fetchHeaders('inbox', null, 1);
        $this->assertFalse($r->complete);
        $this->assertTrue($r->coldStart);
        $this->assertNull($r->cursor->deltaLink());
        $this->assertSame('initial', $r->cursor->get('cold_start'));
        $this->routes = ['$skiptoken=C1' => ['status' => 200, 'body' => ['value' => [], '@odata.deltaLink' => self::DELTA . 'D9']]];
        $r2 = $this->connector()->fetchHeaders('inbox', $r->cursor, 1);
        $this->assertTrue($r2->coldStart);
        $this->assertTrue($r2->complete);
        $this->assertNull($r2->cursor->get('cold_start'));
        $this->assertStringEndsWith('D9', $r2->cursor->deltaLink());
    }

    public function test_expired_delta_restarts_cold(): void
    {
        $this->routes['$deltatoken=OLD'] = ['status' => 410, 'body' => ['error' => ['code' => 'SyncStateNotFound', 'message' => 'x']]];
        $this->routes['messages/delta?$filter'] = ['status' => 200, 'body' => ['value' => [], '@odata.deltaLink' => self::DELTA . 'NEW']];
        $r = $this->connector()->fetchHeaders('inbox', MailboxState::graph(self::DELTA . 'OLD', 'inbox'), 50);
        $this->assertTrue($r->coldStart);
        $this->assertSame(FetchResult::REASON_DELTA_EXPIRED, $r->coldStartReason);
        $this->assertStringEndsWith('NEW', $r->cursor->deltaLink());
    }

    public function test_removed_entries_are_not_reported_as_new_mail(): void
    {
        $this->routes['$deltatoken=D1'] = ['status' => 200, 'body' => ['value' => [['id' => 'GONE', '@removed' => ['reason' => 'deleted']]], '@odata.deltaLink' => self::DELTA . 'D2']];
        $r = $this->connector()->fetchHeaders('inbox', MailboxState::graph(self::DELTA . 'D1', 'inbox'), 50);
        $this->assertSame([], $r->headers);
        $this->assertCount(1, $this->calls, 'no message GET for a removed entry');
    }

    public function test_message_deleted_between_delta_and_get_is_skipped(): void
    {
        $this->routes['$deltatoken=D1'] = ['status' => 200, 'body' => ['value' => [['id' => 'AAA1']], '@odata.deltaLink' => self::DELTA . 'D2']];
        $this->routes['/me/messages/AAA1?'] = ['status' => 404, 'body' => ['error' => ['code' => 'ErrorItemNotFound', 'message' => 'gone']]];
        $r = $this->connector()->fetchHeaders('inbox', MailboxState::graph(self::DELTA . 'D1', 'inbox'), 50);
        $this->assertSame([], $r->headers);
    }

    public function test_body_is_parsed_from_the_raw_mime(): void
    {
        $this->routes['/me/messages/AAA1/$value'] = ['status' => 200, 'body' => (string) file_get_contents(__DIR__ . '/fixtures/graph/plain.eml')];
        $b = $this->connector()->fetchBody('AAA1');
        $this->assertSame('AAA1', $b->providerMessageId);
        $this->assertStringContainsString('Hello from Graph', $b->text);
    }

    public function test_list_folders_walks_children_and_maps_well_known_roles(): void
    {
        $this->routes['/mailFolders?$top='] = ['status' => 200, 'body' => ['value' => [
            ['id' => 'I', 'displayName' => 'Inbox', 'childFolderCount' => 1],
            ['id' => 'S', 'displayName' => 'Sent Items', 'childFolderCount' => 0],
        ]]];
        $this->routes['/mailFolders/I/childFolders'] = ['status' => 200, 'body' => ['value' => [['id' => 'C', 'displayName' => 'Clients', 'childFolderCount' => 0]]]];
        $this->routes['/mailFolders/inbox?$select=id'] = ['status' => 200, 'body' => ['id' => 'I']];
        $this->routes['/mailFolders/sentitems?$select=id'] = ['status' => 200, 'body' => ['id' => 'S']];
        $this->routes['/mailFolders/'] = ['status' => 404, 'body' => ['error' => ['code' => 'ErrorItemNotFound', 'message' => 'x']]];
        $rows = $this->connector()->listFolders();
        $this->assertSame(['I' => 'inbox', 'S' => 'sent'], array_column(array_filter($rows, fn ($r) => isset($r['role'])), 'role', 'id'));
        $this->assertContains('Inbox/Clients', array_column($rows, 'name'));
        $this->assertCount(3, $rows);
    }

    public function test_users_base_path_is_used_for_app_only(): void
    {
        $this->routes['mailFolders/inbox?$select=id'] = ['status' => 200, 'body' => ['id' => 'I']];
        (new GraphConnector($this->tokens, '/users/' . rawurlencode('a@b.com'), $this->http()))->verify();
        $this->assertStringStartsWith('https://graph.microsoft.com/v1.0/users/a%40b.com/', $this->calls[0]['url']);
    }
}
