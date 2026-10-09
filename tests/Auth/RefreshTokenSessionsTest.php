<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\RefreshTokenService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Auth/RefreshTokenStore.php';
require_once __DIR__ . '/../../src/Auth/SessionLifetime.php';
require_once __DIR__ . '/../../src/Auth/DeviceLabel.php';
require_once __DIR__ . '/../../src/Auth/RefreshTokenService.php';
require_once __DIR__ . '/ArrayRefreshTokenStore.php';

final class RefreshTokenSessionsTest extends TestCase
{
    private int $clock = 1000000;
    private ArrayRefreshTokenStore $store;
    private RefreshTokenService $svc;

    protected function setUp(): void
    {
        $this->store = new ArrayRefreshTokenStore();
        $this->svc = new RefreshTokenService($this->store, ['secret' => 'k', 'refresh_expire' => 2592000, 'refresh_family_expire' => 7776000], fn () => $this->clock);
    }

    private function minter(): callable
    {
        return fn (int $id) => ['token' => 'JWT', 'expires' => $this->clock + 900, 'status' => 'success'];
    }

    private function login(int $user, string $ua, string $ip): string
    {
        $this->store->now = $this->clock;
        return $this->svc->mintForLogin($user, $ua, $ip);
    }

    public function testListsOnlyTheCallersLiveFamiliesCurrentFirst(): void
    {
        $a = $this->login(1, 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36', '203.0.113.9');
        $this->clock += 10;
        $b = $this->login(1, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1', '2001:db8:1:2::1');
        $this->login(2, 'Firefox/120.0 X11; Linux', '198.51.100.1');

        $list = $this->svc->sessionsFor(1, $a);
        $this->assertCount(2, $list);
        $this->assertTrue($list[0]['current']);
        $this->assertSame('Chrome on Windows', $list[0]['device']);
        $this->assertSame('203.0.x.x', $list[0]['ip']);
        $this->assertFalse($list[1]['current']);
        $this->assertSame('Safari on iOS', $list[1]['device']);
        $this->assertSame('2001:db8:1::…', $list[1]['ip']);

        // Another user's token marks nothing current.
        $this->assertSame([false, false], array_column($this->svc->sessionsFor(1, 'nope'), 'current'));
        $foreign = $this->login(3, 'x', '1.2.3.4');
        $this->assertSame([false, false], array_column($this->svc->sessionsFor(1, $foreign), 'current'));
    }

    public function testRotationKeepsOneEntryAndUpdatesDeviceAndLastUsed(): void
    {
        $a = $this->login(1, 'Chrome/1 Windows', '1.1.1.1');
        $this->clock += 100;
        $this->store->now = $this->clock;
        $next = $this->svc->redeem($a, '9.9.9.9', $this->minter(), 'Firefox/9 X11; Linux')['refresh_token'];
        $list = $this->svc->sessionsFor(1, $next);
        $this->assertCount(1, $list);
        $this->assertSame('Firefox on Linux', $list[0]['device']);
        $this->assertSame('9.9.x.x', $list[0]['ip']);
        $this->assertSame(1000100, $list[0]['last_used']);
        $this->assertSame(1000000, $list[0]['created']);
        // Within REUSE_GRACE the previous (rotated) token still resolves to the live family.
        $this->assertTrue($this->svc->sessionsFor(1, $a)[0]['current']);
    }

    public function testAStaleRotatedTokenNeverPicksTheKeptFamilyAndRevokesIt(): void
    {
        $a = $this->login(1, 'Chrome/1 Windows', '1.1.1.1');
        $other = $this->login(1, 'Firefox/9 X11; Linux', '2.2.2.2');
        $this->clock += 100;
        $this->store->now = $this->clock;
        $this->svc->redeem($a, '1.1.1.1', $this->minter());
        $this->clock += 3600;   // far past REUSE_GRACE
        $this->store->now = $this->clock;
        $this->assertFalse($this->svc->revokeAllExceptTokenFamily(1, $a));
        // The replay revoked the family of $a (theft signal); the other device is untouched.
        $list = $this->svc->sessionsFor(1, $other);
        $this->assertCount(1, $list);
        $this->assertTrue($list[0]['current']);
    }

    public function testRevokedAndExpiredFamiliesAreNotListed(): void
    {
        $a = $this->login(1, 'a', '1.1.1.1');
        $b = $this->login(1, 'b', '1.1.1.2');
        $this->svc->revokeByToken($b, '1.1.1.2');
        $this->assertCount(1, $this->svc->sessionsFor(1, $a));
        $this->clock += 8000000;
        $this->assertSame([], $this->svc->sessionsFor(1, $a));
    }

    public function testRevokeSessionByIdIsOwnerScoped(): void
    {
        $this->login(1, 'a', '1.1.1.1');
        $this->login(2, 'b', '1.1.1.2');
        $mine = $this->svc->sessionsFor(1)[0]['id'];
        $theirs = $this->svc->sessionsFor(2)[0]['id'];
        $this->assertFalse($this->svc->revokeSession(1, $theirs));
        $this->assertCount(1, $this->svc->sessionsFor(2));
        $this->assertFalse($this->svc->revokeSession(1, 'abc'));
        $this->assertTrue($this->svc->revokeSession(1, $mine));
        $this->assertSame([], $this->svc->sessionsFor(1));
        $this->assertFalse($this->svc->revokeSession(1, $mine));   // already gone
    }

    public function testRevokeOthersKeepsExactlyTheCurrentFamily(): void
    {
        $a = $this->login(1, 'a', '1.1.1.1');
        $this->login(1, 'b', '1.1.1.2');
        $this->login(1, 'c', '1.1.1.3');
        $other = $this->login(2, 'd', '1.1.1.4');

        $this->assertFalse($this->svc->revokeAllExceptTokenFamily(1, $other));   // foreign token refused
        $this->assertCount(3, $this->svc->sessionsFor(1));
        $this->assertFalse($this->svc->revokeAllExceptTokenFamily(1, 'garbage'));

        $this->assertTrue($this->svc->revokeAllExceptTokenFamily(1, $a));
        $left = $this->svc->sessionsFor(1, $a);
        $this->assertCount(1, $left);
        $this->assertTrue($left[0]['current']);
        $this->assertCount(1, $this->svc->sessionsFor(2));

        $this->assertSame(0, $this->svc->revokeOtherSessions(1, $this->store->rows[1]['family_id']));
    }
}
