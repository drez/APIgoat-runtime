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

    /**
     * A folder served like Graph: `$filter=receivedDateTime le X`, `$orderby=receivedDateTime desc`,
     * `$top`, `$skip` honoured over $this->folder (which a test may change between calls). Rows that
     * share an instant come back in an order that flips on every request (Graph promises none).
     *
     * @param ?callable $after fn(int $callNo): void, run after each answer
     */
    private function serveFolder(?callable $after = null): void
    {
        $n = 0;
        $this->routes['/mailFolders/inbox/messages?'] = function (string $method, string $url) use (&$n, $after) {
            $q = rawurldecode((string) parse_url($url, PHP_URL_QUERY));
            preg_match('/\$top=(\d+)/', $q, $top);
            preg_match('/\$skip=(\d+)/', $q, $skip);
            $le   = preg_match('/\$filter=receivedDateTime le (\S+?)(&|$)/', $q, $f) ? strtotime($f[1]) : null;
            $rows = array_values(array_filter($this->folder, fn ($r) => $le === null || strtotime($r['receivedDateTime']) <= $le));
            $flip = $n % 2 === 1;
            usort($rows, fn ($a, $b) => [strtotime($b['receivedDateTime']), $flip ? $b['id'] : $a['id']] <=> [strtotime($a['receivedDateTime']), $flip ? $a['id'] : $b['id']]);
            $off  = (int) ($skip[1] ?? 0);
            $page = array_slice($rows, $off, (int) $top[1]);
            $body = ['value' => $page];
            if (count($rows) > $off + (int) $top[1]) {
                $body['@odata.nextLink'] = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages?' . preg_replace('/&\$skip=\d+/', '', $q) . '&$skip=' . ($off + (int) $top[1]);
            }
            $n++;
            if ($after !== null) $after($n);
            return ['status' => 200, 'body' => $body];
        };
    }

    /** @var list<array{id:string, receivedDateTime:string}> */
    private array $folder = [];

    private static function row(string $id, string $at): array
    {
        return ['id' => $id, 'receivedDateTime' => $at];
    }

    /** @return list<string> every id a backfill walk returned, in order */
    private function walk(int $max, int $cap = 50): array
    {
        $c   = $this->connector();
        $ids = [];
        $tok = null;
        for ($i = 0; $i < $cap; $i++) {
            $r   = $c->fetchBefore('inbox', $tok, $max);
            $ids = array_merge($ids, array_column($r->headers, 'provider_message_id'));
            if ($r->complete) {
                $this->assertNull($r->next);
                return $ids;
            }
            $this->assertNotSame($tok, $r->next, 'every page moves the walk');
            $tok = $r->next;
        }
        $this->fail('the walk never completed');
    }

    public function test_backfill_pages_by_received_time_with_a_boundary_token(): void
    {
        $this->folder = [self::row('A', '2026-10-01T10:05:00Z'), self::row('B', '2026-10-01T10:00:00Z'),
                         self::row('C', '2026-10-01T10:00:00Z'), self::row('E', '2026-10-01T09:00:00Z')];
        $this->serveFolder();
        $r = $this->connector()->fetchBefore('inbox', null, 2);
        $this->assertSame(['A', 'B'], array_column($r->headers, 'provider_message_id'));
        $this->assertSame('inbox', $r->headers[0]['folder_at_fetch']);
        $this->assertSame('2026-10-01T10:00:00Z|B', $r->next);
        $this->assertFalse($r->complete);
        $first = rawurldecode($this->calls[0]['url']);
        $this->assertStringContainsString('$orderby=receivedDateTime desc', $first);
        $this->assertStringContainsString('$top=2', $first);
        $this->assertStringNotContainsString('$filter', $first);

        $r2 = $this->connector()->fetchBefore('inbox', $r->next, 2);
        $second = rawurldecode($this->calls[1]['url']);
        $this->assertStringContainsString('$filter=receivedDateTime le 2026-10-01T10:00:00Z', $second);
        $this->assertStringContainsString('$orderby=receivedDateTime desc', $second);
        $this->assertStringContainsString('$top=3', $second, 'max + the ids already returned at the boundary');
        $this->assertSame(['C', 'E'], array_column($r2->headers, 'provider_message_id'));
        $this->assertNull($r2->next);
        $this->assertTrue($r2->complete);
    }

    public function test_backfill_returns_same_second_messages_across_pages_exactly_once(): void
    {
        $this->folder = [self::row('A', '2026-10-01T10:05:00Z')];
        foreach (range(1, 7) as $i) {
            $this->folder[] = self::row('S' . $i, '2026-10-01T10:00:00Z');   // a whole page and more on one instant
        }
        $this->folder[] = self::row('Z', '2026-10-01T08:00:00Z');
        $this->serveFolder();
        $ids = $this->walk(2);
        $this->assertSame(count($this->folder), count($ids), 'no repeats: ' . implode(',', $ids));
        $this->assertEqualsCanonicalizing(array_column($this->folder, 'id'), $ids);
    }

    public function test_backfill_loses_nothing_when_messages_leave_the_folder_between_pages(): void
    {
        foreach (range(1, 8) as $i) {
            $this->folder[] = self::row('M' . $i, sprintf('2026-10-01T10:%02d:00Z', 60 - $i * 5));
        }
        $this->serveFolder();
        $c = $this->connector();
        $r = $c->fetchBefore('inbox', null, 3);
        $this->assertSame(['M1', 'M2', 'M3'], array_column($r->headers, 'provider_message_id'));
        // the user files the three newest away: an offset page would now skip M4..M6
        $this->folder = array_values(array_filter($this->folder, fn ($m) => !in_array($m['id'], ['M1', 'M2', 'M3'], true)));
        $ids = [];
        for ($tok = $r->next; $tok !== null; $tok = $r->next) {
            $r   = $c->fetchBefore('inbox', $tok, 3);
            $ids = array_merge($ids, array_column($r->headers, 'provider_message_id'));
        }
        $this->assertSame(['M4', 'M5', 'M6', 'M7', 'M8'], $ids);
    }

    public function test_backfill_refuses_a_malformed_token_before_any_call(): void
    {
        foreach ([
            'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages?$skip=2',   // the old nextLink form
            'https://evil.example/v1.0/me/messages',
            '2026-10-01T00:00:00Z|',
            '2026-10-01T00:00:00Z',
            '2026-10-01 00:00:00|B',
            '2026-10-01T00:00:00+02:00|B',
            '2026-10-01T00:00:00Z|A B',
            "2026-10-01T00:00:00Z|A'",
            '2026-10-01T00:00:00Z|A,,B',
            '2026-10-01T00:00:00Z|A&$filter=x',
            '2026-13-45T00:00:00Z|A',
        ] as $bad) {
            try {
                $this->connector()->fetchBefore('inbox', $bad, 5);
                $this->fail('expected InvalidArgumentException for ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->assertSame([], $this->calls, $bad);
            }
        }
    }

    public function test_list_ids_walks_the_whole_folder_by_received_time(): void
    {
        foreach (range(1, 1203) as $i) {   // 3 pages of 500, ties on every instant
            $this->folder[] = self::row(sprintf('L%04d', $i), sprintf('2026-10-01T%02d:00:00Z', intdiv($i, 60)));
        }
        $this->serveFolder();
        $l = $this->connector()->listIds('inbox');
        $this->assertCount(1203, $l->ids);
        $this->assertTrue($l->complete);
        $this->assertGreaterThanOrEqual(3, count($this->calls));
        $first = rawurldecode($this->calls[0]['url']);
        $this->assertStringContainsString('$select=id,receivedDateTime', $first);
        $this->assertStringContainsString('$orderby=receivedDateTime desc', $first);
        $this->assertStringContainsString('$filter=receivedDateTime le ', rawurldecode($this->calls[1]['url']));
    }

    public function test_list_ids_misses_nothing_when_messages_leave_the_folder_mid_walk(): void
    {
        foreach (range(1, 1100) as $i) {
            $this->folder[] = self::row(sprintf('L%04d', $i), sprintf('2026-10-01T%02d:%02d:00Z', 23 - intdiv($i, 60), 59 - $i % 60));
        }
        $gone = array_column(array_slice($this->folder, 0, 400), 'id');
        $this->serveFolder(function (int $call) use ($gone): void {
            if ($call === 1) {   // after the first page: 400 of the newest leave the folder
                $this->folder = array_values(array_filter($this->folder, fn ($m) => !in_array($m['id'], $gone, true)));
            }
        });
        $l = $this->connector()->listIds('inbox');
        foreach (array_slice(array_column($this->folder, 'id'), 0) as $id) {
            $this->assertTrue($l->has($id), $id . ' is still in the folder and must be listed');
        }
        $this->assertTrue($l->complete);
    }

    public function test_backfill_without_nextLink_is_complete(): void
    {
        $this->routes['/messages?'] = ['status' => 200, 'body' => ['value' => []]];
        $r = $this->connector()->fetchBefore('inbox', null, 5);
        $this->assertNull($r->next);
        $this->assertTrue($r->complete);
    }

    public function test_append_rolls_back_the_created_message_when_the_patch_fails(): void
    {
        $this->routes['POST https://graph.microsoft.com/v1.0/me/mailFolders/F1/messages'] = ['status' => 201, 'body' => ['id' => 'NEW1']];
        $this->routes['PATCH '] = ['status' => 500, 'body' => 'boom'];
        $this->routes['DELETE https://graph.microsoft.com/v1.0/me/messages/NEW1'] = ['status' => 500, 'body' => 'boom again'];
        try {
            $this->connector()->append('F1', 'x', true);
            $this->fail('expected TransientError');
        } catch (TransientError $e) {
            $this->assertStringContainsString('PATCH', $e->getMessage(), 'the PATCH error is rethrown, not the DELETE one');
        }
        $this->assertSame('DELETE', end($this->calls)['method']);
    }

    public function test_append_defaults_to_the_inbox(): void
    {
        $this->routes['POST '] = ['status' => 201, 'body' => ['id' => 'NEW1']];
        $this->routes['PATCH '] = ['status' => 200, 'body' => ['id' => 'NEW1']];
        $this->connector()->append('', 'x', true);
        $this->assertStringContainsString('/me/mailFolders/inbox/messages', $this->calls[0]['url']);
    }

    public function test_ensure_folder_recovers_from_a_create_race(): void
    {
        $lookups = 0;
        $this->routes['GET '] = function () use (&$lookups) {
            return ['status' => 200, 'body' => ['value' => ++$lookups === 1 ? [] : [['id' => 'RACED', 'displayName' => 'Clients']]]];
        };
        $this->routes['POST '] = ['status' => 409, 'body' => ['error' => ['code' => 'ErrorFolderExists', 'message' => 'exists']]];
        $this->assertSame('RACED', $this->connector()->ensureFolder('Clients'));
        $this->assertSame(2, $lookups);
    }

    public function test_ensure_folder_rethrows_a_409_when_the_folder_still_is_not_there(): void
    {
        $this->routes['GET '] = ['status' => 200, 'body' => ['value' => []]];
        $this->routes['POST '] = ['status' => 409, 'body' => ['error' => ['code' => 'ErrorFolderExists', 'message' => 'exists']]];
        $this->expectException(TransientError::class);
        $this->connector()->ensureFolder('Clients');
    }
}
