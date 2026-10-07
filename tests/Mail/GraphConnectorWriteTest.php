<?php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeTokenSource.php';

use ApiGoat\Mail\Connector\GraphConnector;
use ApiGoat\Mail\FolderLister;
use ApiGoat\Mail\FolderWriter;
use ApiGoat\Mail\StateWriter;
use ApiGoat\Sync\Exceptions\TransientError;
use PHPUnit\Framework\TestCase;

final class GraphConnectorWriteTest extends TestCase
{
    private const B = 'https://graph.microsoft.com/v1.0/me';

    /** @var array<int,array{method:string,url:string,headers:string[],body:?string}> */
    private array $calls = [];
    /** @var array<string,mixed> route substring → response (array or callable) */
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
                    if (is_callable($resp)) $resp = $resp($method, $url, $body);
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

    private function bodies(): array
    {
        return array_map(fn ($c) => json_decode((string) $c['body'], true), $this->calls);
    }

    public function test_implements_the_optional_interfaces(): void
    {
        $c = $this->connector();
        $this->assertInstanceOf(FolderLister::class, $c);
        $this->assertInstanceOf(StateWriter::class, $c);
        $this->assertInstanceOf(FolderWriter::class, $c);
    }

    public function test_move_posts_destination_and_returns_the_same_immutable_id(): void
    {
        $this->routes['POST https://graph.microsoft.com/v1.0/me/messages/AAA1/move'] = ['status' => 201, 'body' => ['id' => 'AAA1']];
        $this->assertSame('AAA1', $this->connector()->move('AAA1', 'F9'));
        $this->assertSame(['destinationId' => 'F9'], json_decode((string) $this->calls[0]['body'], true));
    }

    public function test_trash_archive_untrash_use_well_known_folders(): void
    {
        $this->routes['/move'] = fn ($m, $u, $b) => ['status' => 201, 'body' => ['id' => 'AAA1']];
        $c = $this->connector();
        $c->trash('AAA1');
        $c->archive('AAA1');
        $c->untrash('AAA1');
        $this->assertSame(['deleteditems', 'archive', 'inbox'], array_map(fn ($x) => $x['destinationId'], $this->bodies()));
    }

    public function test_archive_and_untrash_accept_an_explicit_folder(): void
    {
        $this->routes['/move'] = ['status' => 201, 'body' => ['id' => 'AAA1']];
        $c = $this->connector();
        $this->assertSame('AAA1', $c->archive('AAA1', 'FARC'));
        $this->assertSame('AAA1', $c->untrash('AAA1', 'FIN'));
        $this->assertSame(['FARC', 'FIN'], array_map(fn ($x) => $x['destinationId'], $this->bodies()));
    }

    public function test_set_flag_and_mark_read_patch_the_message(): void
    {
        $this->routes['PATCH https://graph.microsoft.com/v1.0/me/messages/AAA1'] = ['status' => 200, 'body' => ['id' => 'AAA1']];
        $c = $this->connector();
        $c->setFlag('AAA1', true);
        $c->setFlag('AAA1', false);
        $c->markRead('AAA1', true);
        $c->markRead('AAA1', false);
        $this->assertSame([
            ['flag' => ['flagStatus' => 'flagged']],
            ['flag' => ['flagStatus' => 'notFlagged']],
            ['isRead' => true],
            ['isRead' => false],
        ], $this->bodies());
        $this->assertSame('PATCH', $this->calls[0]['method']);
    }

    public function test_message_state_reads_flags_and_the_parent_folder_role(): void
    {
        $this->routes['/me/messages/AAA1?'] = ['status' => 200, 'body' => ['id' => 'AAA1', 'isRead' => true, 'flag' => ['flagStatus' => 'flagged'], 'parentFolderId' => 'FINBOX']];
        $this->routes['/mailFolders/inbox?'] = ['status' => 200, 'body' => ['id' => 'FINBOX']];
        $this->routes['/mailFolders/'] = ['status' => 404, 'body' => ['error' => ['code' => 'ErrorFolderNotFound', 'message' => 'x']]];
        $s = $this->connector()->messageState('AAA1');
        $this->assertTrue($s->seen);
        $this->assertTrue($s->flagged);
        $this->assertSame('FINBOX', $s->folder);
        $this->assertSame('inbox', $s->role);
        $this->assertStringContainsString('$select=isRead,flag,parentFolderId', $this->calls[0]['url']);
    }

    public function test_message_state_in_a_custom_folder_has_no_role(): void
    {
        $this->routes['/me/messages/AAA1?'] = ['status' => 200, 'body' => ['id' => 'AAA1', 'isRead' => false, 'flag' => ['flagStatus' => 'notFlagged'], 'parentFolderId' => 'FX']];
        $this->routes['/mailFolders/'] = ['status' => 200, 'body' => ['id' => 'FOTHER']];
        $s = $this->connector()->messageState('AAA1');
        $this->assertFalse($s->seen);
        $this->assertFalse($s->flagged);
        $this->assertNull($s->role);
    }

    public function test_message_state_of_a_gone_message_is_null(): void
    {
        $this->routes['/me/messages/GONE?'] = ['status' => 404, 'body' => ['error' => ['code' => 'ErrorItemNotFound', 'message' => 'x']]];
        $this->assertNull($this->connector()->messageState('GONE'));
    }

    public function test_message_state_other_errors_still_throw(): void
    {
        $this->routes['/me/messages/AAA1?'] = ['status' => 500, 'body' => 'boom'];
        $this->expectException(TransientError::class);
        $this->connector()->messageState('AAA1');
    }

    public function test_unresolved_ids_are_refused_before_any_call(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->connector()->move('unresolved:7', 'F');
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    /** @dataProvider mutators */
    public function test_every_mutating_method_refuses_unresolved_ids(string $method, array $args): void
    {
        $c = $this->connector();
        try {
            $c->$method('unresolved:7', ...$args);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            $this->assertSame([], $this->calls);
        }
    }

    public static function mutators(): array
    {
        return [
            'markRead' => ['markRead', [true]], 'trash' => ['trash', []], 'archive' => ['archive', []],
            'untrash' => ['untrash', []], 'setFlag' => ['setFlag', [true]], 'messageState' => ['messageState', []],
            'fetchRaw' => ['fetchRaw', []],
        ];
    }

    public function test_list_ids_follows_every_page(): void
    {
        $this->routes['$skiptoken=P2'] = ['status' => 200, 'body' => ['value' => [['id' => 'C']]]];
        $this->routes['/mailFolders/F1/messages?'] = ['status' => 200, 'body' => ['value' => [['id' => 'A'], ['id' => 'B']],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/F1/messages?$select=id&$skiptoken=P2']];
        $l = $this->connector()->listIds('F1');
        $this->assertSame(['A' => true, 'B' => true, 'C' => true], $l->ids);
        $this->assertNull($l->generation);
        $this->assertTrue($l->complete);
        $this->assertStringContainsString('$select=id&$top=500', $this->calls[0]['url']);
        $this->assertCount(2, $this->calls);
    }

    public function test_append_uploads_base64_mime_then_clears_the_draft_flag(): void
    {
        $this->routes['POST https://graph.microsoft.com/v1.0/me/mailFolders/F1/messages'] = ['status' => 201, 'body' => ['id' => 'NEW1']];
        $this->routes['PATCH https://graph.microsoft.com/v1.0/me/messages/NEW1'] = ['status' => 200, 'body' => ['id' => 'NEW1']];
        $this->assertSame('NEW1', $this->connector()->append('F1', "Subject: x\r\n\r\nbody", true));
        $this->assertSame("Subject: x\r\n\r\nbody", base64_decode((string) $this->calls[0]['body']));
        $this->assertContains('Content-Type: text/plain', $this->calls[0]['headers']);
        $patch = json_decode((string) $this->calls[1]['body'], true);
        $this->assertSame('Integer 0x0E07', $patch['singleValueExtendedProperties'][0]['id']);
        $this->assertSame('1', $patch['singleValueExtendedProperties'][0]['value']);
        $this->assertTrue($patch['isRead']);
    }

    public function test_append_unseen_keeps_the_message_unread(): void
    {
        $this->routes['POST '] = ['status' => 201, 'body' => ['id' => 'NEW1']];
        $this->routes['PATCH '] = ['status' => 200, 'body' => ['id' => 'NEW1']];
        $this->connector()->append('F1', 'x', false);
        $this->assertFalse(json_decode((string) $this->calls[1]['body'], true)['isRead']);
    }

    public function test_ensure_folder_creates_missing_segments_only(): void
    {
        $this->routes['GET https://graph.microsoft.com/v1.0/me/mailFolders?'] = ['status' => 200, 'body' => ['value' => [['id' => 'C', 'displayName' => 'clients']]]];
        $this->routes['GET https://graph.microsoft.com/v1.0/me/mailFolders/C/childFolders?'] = ['status' => 200, 'body' => ['value' => []]];
        $this->routes['POST https://graph.microsoft.com/v1.0/me/mailFolders/C/childFolders'] = ['status' => 201, 'body' => ['id' => 'ACME', 'displayName' => 'Acme']];
        $this->assertSame('ACME', $this->connector()->ensureFolder('Clients/Acme'));
        $posts = array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'POST'));
        $this->assertCount(1, $posts);
        $this->assertSame(['displayName' => 'Acme'], json_decode((string) $posts[0]['body'], true));
        $this->assertStringContainsString(rawurlencode("displayName eq 'Clients'"), $this->calls[0]['url']);
    }

    public function test_ensure_folder_returns_an_existing_leaf_without_creating(): void
    {
        $this->routes['GET https://graph.microsoft.com/v1.0/me/mailFolders?'] = ['status' => 200, 'body' => ['value' => [['id' => 'C', 'displayName' => 'Clients']]]];
        $this->routes['GET https://graph.microsoft.com/v1.0/me/mailFolders/C/childFolders?'] = ['status' => 200, 'body' => ['value' => [['id' => 'ACME', 'displayName' => 'Acme']]]];
        $this->assertSame('ACME', $this->connector()->ensureFolder('Clients/Acme'));
        $this->assertSame([], array_filter($this->calls, fn ($c) => $c['method'] === 'POST'));
    }

    public function test_ensure_folder_doubles_single_quotes_in_the_filter(): void
    {
        $this->routes['GET '] = ['status' => 200, 'body' => ['value' => [['id' => 'Q', 'displayName' => "O'Neil"]]]];
        $this->assertSame('Q', $this->connector()->ensureFolder("O'Neil"));
        $this->assertStringContainsString(rawurlencode("displayName eq 'O''Neil'"), $this->calls[0]['url']);
    }

    public function test_backfill_walks_backwards_with_an_opaque_token(): void
    {
        $row = fn (string $id, string $dt) => ['id' => $id, 'receivedDateTime' => $dt, 'subject' => 's' . $id, 'isRead' => true, 'parentFolderId' => 'F'];
        $this->routes['/mailFolders/inbox/messages?'] = ['status' => 200, 'body' => ['value' => [
            $row('A', '2026-10-02T00:00:00Z'), $row('B', '2026-10-01T00:00:00Z')]]];
        $r = $this->connector()->fetchBefore('inbox', null, 2);
        $this->assertCount(2, $r->headers);
        $this->assertSame('A', $r->headers[0]['provider_message_id']);
        $this->assertSame('inbox', $r->headers[0]['folder_at_fetch']);
        $this->assertSame('2026-10-01T00:00:00Z|B', $r->next);
        $this->assertFalse($r->complete);
        $this->assertStringContainsString('$orderby=receivedDateTime desc', rawurldecode($this->calls[0]['url']));
        $this->assertStringContainsString('$top=2', $this->calls[0]['url']);
        $this->assertStringNotContainsString('$filter', $this->calls[0]['url']);

        $this->calls  = [];
        $this->routes = ['/mailFolders/inbox/messages?' => ['status' => 200, 'body' => ['value' => [$row('C', '2026-09-30T00:00:00Z')]]]];
        $r2 = $this->connector()->fetchBefore('inbox', $r->next, 2);
        $this->assertStringContainsString('$filter=receivedDateTime+lt+2026-10-01T00:00:00Z', $this->calls[0]['url']);
        $this->assertSame(['C'], array_column($r2->headers, 'provider_message_id'));
        $this->assertNull($r2->next);
        $this->assertTrue($r2->complete);
    }

    public function test_backfill_skips_the_boundary_row_when_a_page_repeats_it(): void
    {
        $row = fn (string $id, string $dt) => ['id' => $id, 'receivedDateTime' => $dt];
        $this->routes['/messages?'] = ['status' => 200, 'body' => ['value' => [$row('B', '2026-10-01T00:00:00Z'), $row('C', '2026-09-30T00:00:00Z')]]];
        $r = $this->connector()->fetchBefore('F1', '2026-10-01T00:00:00Z|B', 5);
        $this->assertSame(['C'], array_column($r->headers, 'provider_message_id'));
    }
}
