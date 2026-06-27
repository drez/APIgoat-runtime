<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\RefreshTokenService;
use PHPUnit\Framework\TestCase;

final class RefreshTokenServiceTest extends TestCase
{
    private int $clock = 1000000;

    /** integer-seconds TTLs => deterministic expiry math (30d, 90d). */
    private function svc(ArrayRefreshTokenStore $store): RefreshTokenService
    {
        return new RefreshTokenService(
            $store,
            ['secret' => 'k', 'expire' => 'now +15 minutes', 'refresh_expire' => 2592000, 'refresh_family_expire' => 7776000],
            fn () => $this->clock
        );
    }

    private function minter(): callable
    {
        return fn (int $id) => ['token' => 'JWT-for-' . $id, 'expires' => $this->clock + 900, 'status' => 'success'];
    }

    public function testMintStoresHashNotRaw(): void
    {
        $store = new ArrayRefreshTokenStore();
        $raw = $this->svc($store)->mintForLogin(7);

        $this->assertNotSame('', $raw);
        $row = array_values($store->rows)[0];
        $this->assertSame(7, $row['id_authy']);
        $this->assertSame(hash('sha256', $raw), $row['token_hash']);
        $this->assertNotSame($raw, $row['token_hash']);
        $this->assertSame($this->clock + 2592000, $row['expires']);
        $this->assertSame($this->clock + 7776000, $row['family_expires']);
        $this->assertSame('No', $row['revoked']);
    }

    public function testRedeemRotatesWithinSameFamily(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc = $this->svc($store);
        $raw = $svc->mintForLogin(7);
        $family = array_values($store->rows)[0]['family_id'];

        $this->clock += 100;
        $out = $svc->redeem($raw, '1.2.3.4', $this->minter());

        $this->assertSame('success', $out['status']);
        $this->assertSame('JWT-for-7', $out['token']);
        $this->assertArrayHasKey('refresh_token', $out);
        $this->assertNotSame($raw, $out['refresh_token']);
        // old revoked, exactly one live token, same family
        $this->assertSame(1, $store->liveCountForFamily($family));
        $newRow = $store->findByHash(hash('sha256', $out['refresh_token']));
        $this->assertSame($family, $newRow['family_id']);
    }

    public function testReusedTokenRevokesEntireFamily(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc = $this->svc($store);
        $raw = $svc->mintForLogin(7);
        $family = array_values($store->rows)[0]['family_id'];

        $out = $svc->redeem($raw, '1.2.3.4', $this->minter());   // rotate: raw now revoked, raw2 live
        $reuse = $svc->redeem($raw, '1.2.3.4', $this->minter());  // present the OLD (revoked) token again

        $this->assertSame('error', $reuse['status']);
        $this->assertSame('token_reuse', $reuse['message']);
        $this->assertSame(0, $store->liveCountForFamily($family)); // whole family killed
    }

    public function testUnknownTokenIsInvalid(): void
    {
        $store = new ArrayRefreshTokenStore();
        $out = $this->svc($store)->redeem('nope', '1.2.3.4', $this->minter());
        $this->assertSame('error', $out['status']);
        $this->assertSame('invalid_token', $out['message']);
    }

    public function testExpiredTokenRejectedAndRevoked(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc = $this->svc($store);
        $raw = $svc->mintForLogin(7);

        $this->clock += 2592000 + 1; // past per-token TTL
        $out = $svc->redeem($raw, '1.2.3.4', $this->minter());

        $this->assertSame('expired', $out['message']);
        $row = $store->findByHash(hash('sha256', $raw));
        $this->assertSame('Yes', $row['revoked']);
    }

    public function testRotationClampsToFamilyCapAndNeverExtendsIt(): void
    {
        $store = new ArrayRefreshTokenStore();
        // 86-day per-token TTL, 90-day family — token minted at T=0 is still
        // valid at T=85d, where a fresh 86d window would exceed the family cap.
        $svc = new RefreshTokenService(
            $store,
            ['secret' => 'k', 'refresh_expire' => 86 * 86400, 'refresh_family_expire' => 7776000],
            fn () => $this->clock
        );
        $raw = $svc->mintForLogin(7);
        $familyExpires = array_values($store->rows)[0]['family_expires'];

        // jump to 5 days before the family cap; a fresh 86d token would exceed it
        $this->clock = $familyExpires - 5 * 86400;
        $out = $svc->redeem($raw, '1.2.3.4', $this->minter());

        $newRow = $store->findByHash(hash('sha256', $out['refresh_token']));
        $this->assertSame($familyExpires, $newRow['expires']);          // clamped to the cap
        $this->assertSame($familyExpires, $newRow['family_expires']);   // cap copied forward unchanged
    }

    public function testThrottleTripsAfterTooManyAttempts(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc = $this->svc($store);
        for ($i = 0; $i < 25; $i++) {
            $svc->redeem('bad', '9.9.9.9', $this->minter());
        }
        $out = $svc->redeem('bad', '9.9.9.9', $this->minter());
        $this->assertSame('rate_limited', $out['message']);
    }

    public function testRevokeAllForUser(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc = $this->svc($store);
        $svc->mintForLogin(7);
        $svc->mintForLogin(7);
        $svc->revokeAllForUser(7);
        $live = array_filter($store->rows, fn ($r) => $r['revoked'] === 'No' && $r['id_authy'] === 7);
        $this->assertCount(0, $live);
    }

    public function testStringTtlAnchorsToInjectedClock(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc = new RefreshTokenService(
            $store,
            ['secret' => 'k', 'expire' => 'now +15 minutes', 'refresh_expire' => 'now +30 days', 'refresh_family_expire' => 'now +90 days'],
            fn () => $this->clock
        );
        $svc->mintForLogin(7);
        $row = array_values($store->rows)[0];
        $this->assertSame($this->clock + 30 * 86400, $row['expires']);
        $this->assertSame($this->clock + 90 * 86400, $row['family_expires']);
    }
}
