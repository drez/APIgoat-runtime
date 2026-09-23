<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\RefreshTokenService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Auth/RefreshTokenStore.php';
require_once __DIR__ . '/../../src/Auth/SessionLifetime.php';
require_once __DIR__ . '/../../src/Auth/RefreshTokenService.php';
require_once __DIR__ . '/ArrayRefreshTokenStore.php';

/** Review-3 #7: the REUSE_GRACE replay path must never redeem past exp. */
final class RefreshTokenGraceExpiryTest extends TestCase
{
    private int $clock = 1000000;
    private int $mints = 0;

    private function svc(ArrayRefreshTokenStore $store, int $ttl = 2592000, int $familyTtl = 7776000): RefreshTokenService
    {
        return new RefreshTokenService(
            $store,
            ['secret' => 'k', 'refresh_expire' => $ttl, 'refresh_family_expire' => $familyTtl],
            fn () => $this->clock
        );
    }

    private function minter(): callable
    {
        return function (int $id) {
            $this->mints++;
            return ['token' => 'JWT-for-' . $id, 'expires' => $this->clock + 900, 'status' => 'success'];
        };
    }

    /** The expiry branch stamps last_used_at: a 2nd presentation used to fall into grace and mint. */
    public function testExpiredTokenPresentedTwiceInsideGraceIsNeverRedeemed(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc = $this->svc($store);
        $raw = $svc->mintForLogin(7);

        $this->clock += 2592000 + 1;
        $this->assertSame('expired', $svc->redeem($raw, '1.2.3.4', $this->minter())['message']);

        $this->clock += 2;
        $out = $svc->redeem($raw, '1.2.3.4', $this->minter());
        $this->assertSame('error', $out['status']);
        $this->assertSame('expired', $out['message']);
        $this->assertArrayNotHasKey('token', $out);
        $this->assertSame(0, $this->mints);
    }

    /** Rotated 1s before exp; a straggler of the same token lands just after exp. */
    public function testGraceStragglerAfterPresentedTokenExpiryIsRefused(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc = $this->svc($store, 100);
        $raw = $svc->mintForLogin(7);

        $this->clock += 99;
        $this->assertSame('success', $svc->redeem($raw, '1.2.3.4', $this->minter())['status']);
        $this->mints = 0;

        $this->clock += 5;                                  // inside REUSE_GRACE, past the token's exp
        $out = $svc->redeem($raw, '1.2.3.4', $this->minter());
        $this->assertSame('expired', $out['message'] ?? null);
        $this->assertSame(0, $this->mints);
    }

    /** Family ceiling reached between rotation and the grace replay. */
    public function testGraceReplayPastFamilyExpiryIsRefused(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc = $this->svc($store, 2592000, 100);
        $raw = $svc->mintForLogin(7);

        $this->clock += 98;
        $first = $svc->redeem($raw, '1.2.3.4', $this->minter());
        $this->assertSame('success', $first['status']);
        $this->mints = 0;

        $this->clock += 5;
        $out = $svc->redeem($raw, '1.2.3.4', $this->minter());
        $this->assertSame('expired', $out['message'] ?? null);
        $this->assertSame(0, $this->mints);
    }

    /** Sanity: a live grace straggler still gets the same successor. */
    public function testLiveGraceStragglerStillServed(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc = $this->svc($store);
        $raw = $svc->mintForLogin(7);

        $first = $svc->redeem($raw, '1.2.3.4', $this->minter());
        $this->clock += 5;
        $second = $svc->redeem($raw, '1.2.3.4', $this->minter());
        $this->assertSame('success', $second['status']);
        $this->assertSame($first['refresh_token'], $second['refresh_token']);
    }
}
