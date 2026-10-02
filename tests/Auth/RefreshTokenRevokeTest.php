<?php
// /var/www/gc/vendor/apigoat/runtime/tests/Auth/RefreshTokenRevokeTest.php
declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\RefreshTokenService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Auth/RefreshTokenStore.php';
require_once __DIR__ . '/../../src/Auth/SessionLifetime.php';
require_once __DIR__ . '/../../src/Auth/RefreshTokenService.php';
require_once __DIR__ . '/ArrayRefreshTokenStore.php';

final class RefreshTokenRevokeTest extends TestCase
{
    private int $clock = 1000000;

    private function svc(ArrayRefreshTokenStore $store): RefreshTokenService
    {
        return new RefreshTokenService($store, ['secret' => 'k', 'expire' => 'now +15 minutes', 'refresh_expire' => 2592000, 'refresh_family_expire' => 7776000], fn () => $this->clock);
    }

    private function minter(): callable
    {
        return fn (int $id) => ['token' => 'JWT-' . $id, 'expires' => $this->clock + 900, 'status' => 'success'];
    }

    public function testRevokeKillsTheWholeFamilyIncludingTheRotatedSuccessor(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc   = $this->svc($store);
        $first = $svc->mintForLogin(7);
        $this->clock += 100;
        $next  = $svc->redeem($first, '1.1.1.1', $this->minter())['refresh_token'];

        $this->assertSame(['status' => 'success'], $svc->revokeByToken($next, '1.1.1.1'));

        $this->clock += 100;
        $again = $svc->redeem($next, '1.1.1.1', $this->minter());
        $this->assertSame('error', $again['status']);
    }

    public function testAnUnknownTokenAnswersSuccessWithoutAnOracle(): void
    {
        $this->assertSame(['status' => 'success'], $this->svc(new ArrayRefreshTokenStore())->revokeByToken('nope', '1.1.1.1'));
    }

    public function testAnEmptyTokenIsInvalid(): void
    {
        $this->assertSame('invalid_token', $this->svc(new ArrayRefreshTokenStore())->revokeByToken('', '1.1.1.1')['message']);
    }

    public function testRevokeIsThrottledLikeRedeem(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc   = $this->svc($store);
        for ($i = 0; $i < RefreshTokenService::THROTTLE_MAX; $i++) {
            $svc->revokeByToken('x' . $i, '9.9.9.9');
        }
        $this->assertSame('rate_limited', $svc->revokeByToken('y', '9.9.9.9')['message']);
    }

    public function testTheRevokeRouteIsATokenExchangeForTheSessionLayer(): void
    {
        $this->assertTrue(\ApiGoat\Auth\SessionLifetime::isApiTokenExchange(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/apigmail/.admin/api/v1/Authy/revoke']));
    }

    public function testRevokeIsIdempotent(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc   = $this->svc($store);
        $rt    = $svc->mintForLogin(7);
        $this->assertSame(['status' => 'success'], $svc->revokeByToken($rt, '1.1.1.1'));
        $this->assertSame(['status' => 'success'], $svc->revokeByToken($rt, '1.1.1.1'));
        $this->assertSame('error', $svc->redeem($rt, '1.1.1.1', $this->minter())['status']);
    }

    public function testRevokingWithAnAlreadyRotatedTokenStillKillsTheLiveSuccessor(): void
    {
        // A client signing out with a stale token (another tab/device rotated
        // it) must still end the session: the successor is in the same family.
        $store = new ArrayRefreshTokenStore();
        $svc   = $this->svc($store);
        $first = $svc->mintForLogin(7);
        $this->clock += 100;
        $next  = $svc->redeem($first, '1.1.1.1', $this->minter())['refresh_token'];

        $this->assertSame(['status' => 'success'], $svc->revokeByToken($first, '1.1.1.1'));

        // inside the reuse grace the stale token must NOT be replayed into the revoked successor
        $this->assertSame('error', $svc->redeem($first, '1.1.1.1', $this->minter())['status']);
        $this->clock += 100;
        $this->assertSame('error', $svc->redeem($next, '1.1.1.1', $this->minter())['status']);
    }

    public function testRevokeLeavesOtherFamiliesAlive(): void
    {
        $store = new ArrayRefreshTokenStore();
        $svc   = $this->svc($store);
        $phone = $svc->mintForLogin(7);
        $web   = $svc->mintForLogin(7);
        $svc->revokeByToken($phone, '1.1.1.1');
        $this->assertSame('success', $svc->redeem($web, '1.1.1.1', $this->minter())['status']);
    }
}
