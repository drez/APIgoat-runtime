<?php
namespace ApiGoat\Tests\Microsoft;

require_once __DIR__ . '/../Mail/FakeTokenSource.php';

use ApiGoat\Microsoft\GraphHttp;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;
use ApiGoat\Tests\Mail\FakeTokenSource;
use PHPUnit\Framework\TestCase;

final class GraphHttpTest extends TestCase
{
    public function test_sends_the_immutable_id_preference_and_bearer(): void
    {
        $seen = null;
        $g = new GraphHttp(new FakeTokenSource(), function ($m, $u, $h, $b) use (&$seen) { $seen = $h; return ['status' => 200, 'headers' => '', 'body' => '{"ok":1}']; });
        $this->assertSame(['ok' => 1], $g->call('GET', '/me'));
        $this->assertContains('Prefer: IdType="ImmutableId"', $seen);
        $this->assertContains('Authorization: Bearer tok-1', $seen);
        $this->assertContains('Accept: application/json', $seen);
    }

    public function test_401_invalidates_once_then_auth_failed(): void
    {
        $t = new FakeTokenSource();
        $g = new GraphHttp($t, fn () => ['status' => 401, 'headers' => '', 'body' => '{"error":{"code":"InvalidAuthenticationToken","message":"x"}}']);
        try { $g->call('GET', '/me'); $this->fail('no throw'); } catch (AuthFailed $e) { $this->assertStringContainsString('InvalidAuthenticationToken', $e->getMessage()); }
        $this->assertSame(1, $t->invalidated);
    }

    public function test_429_maps_to_rate_limited_with_retry_after(): void
    {
        $g = new GraphHttp(new FakeTokenSource(), fn () => ['status' => 429, 'headers' => "Retry-After: 45\r\n", 'body' => '{}']);
        try { $g->call('GET', '/me'); $this->fail('no throw'); } catch (RateLimited $e) { $this->assertSame(45, $e->getCode()); }
    }

    public function test_503_without_header_defaults_to_30_seconds(): void
    {
        $g = new GraphHttp(new FakeTokenSource(), fn () => ['status' => 503, 'headers' => '', 'body' => '{}']);
        try { $g->call('GET', '/me'); $this->fail('no throw'); } catch (RateLimited $e) { $this->assertSame(30, $e->getCode()); }
    }

    public function test_410_and_404_are_transient_with_their_status(): void
    {
        foreach ([404, 410] as $s) {
            $g = new GraphHttp(new FakeTokenSource(), fn () => ['status' => $s, 'headers' => '', 'body' => '{}']);
            try { $g->call('GET', '/me'); $this->fail('no throw'); } catch (TransientError $e) { $this->assertSame($s, $e->getCode()); }
        }
    }

    public function test_403_is_auth_failed_naming_the_graph_code(): void
    {
        $g = new GraphHttp(new FakeTokenSource(), fn () => ['status' => 403, 'headers' => '', 'body' => '{"error":{"code":"ErrorAccessDenied","message":"Access is denied."}}']);
        $this->expectException(AuthFailed::class);
        $this->expectExceptionMessage('ErrorAccessDenied');
        $g->call('GET', '/users/a%40b.com/mailFolders/inbox');
    }

    public function test_204_is_empty_array_and_absolute_urls_pass_through_and_raw_returns_body(): void
    {
        $urls = [];
        $g = new GraphHttp(new FakeTokenSource(), function ($m, $u) use (&$urls) {
            $urls[] = $u;
            return count($urls) === 1 ? ['status' => 204, 'headers' => '', 'body' => ''] : ['status' => 200, 'headers' => '', 'body' => 'MIME-RAW'];
        });
        $this->assertSame([], $g->call('DELETE', '/me/messages/1'));
        $this->assertSame('MIME-RAW', $g->call('GET', 'https://graph.microsoft.com/v1.0/me/messages/1/$value?x=1', null, [], true));
        $this->assertSame('https://graph.microsoft.com/v1.0/me/messages/1', $urls[0] === GraphHttp::BASE . '/me/messages/1' ? $urls[0] : '');
        $this->assertSame('https://graph.microsoft.com/v1.0/me/messages/1/$value?x=1', $urls[1]);
    }

    public function test_post_raw_sends_body_as_is_with_content_type(): void
    {
        $seen = [];
        $g = new GraphHttp(new FakeTokenSource(), function ($m, $u, $h, $b) use (&$seen) { $seen = compact('m', 'u', 'h', 'b'); return ['status' => 202, 'headers' => '', 'body' => '']; });
        $this->assertSame([], $g->postRaw('/me/sendMail', 'BASE64==', 'text/plain'));
        $this->assertSame('POST', $seen['m']);
        $this->assertSame('BASE64==', $seen['b']);
        $this->assertContains('Content-Type: text/plain', $seen['h']);
    }
}
