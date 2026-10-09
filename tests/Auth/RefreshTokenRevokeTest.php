<?php
// /var/www/gc/vendor/apigoat/runtime/tests/Auth/RefreshTokenRevokeTest.php
declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\RefreshTokenService;
use ApiGoat\Auth\RefreshTokenStore;
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
    public function testARevokeLandingBetweenClaimAndSuccessorInsertLeavesNoLiveToken(): void
    {
        // I-1: redeem has claimed the rotation (parent flipped to revoked)
        // but not yet inserted the successor when sign-out runs. The insert
        // must not resurrect the family.
        $inner = new ArrayRefreshTokenStore();
        $store = new RaceRefreshTokenStore($inner);
        $svc   = $this->svc($inner);
        $racer = new RefreshTokenService($store, ['secret' => 'k', 'expire' => 'now +15 minutes', 'refresh_expire' => 2592000, 'refresh_family_expire' => 7776000], fn () => $this->clock);
        $rt    = $svc->mintForLogin(7);
        $family = $inner->rows[1]['family_id'];
        $this->clock += 100;
        $store->beforeInsert = fn () => $svc->revokeByToken($rt, '2.2.2.2');

        $res = $racer->redeem($rt, '1.1.1.1', $this->minter());

        $this->assertSame('error', $res['status']);
        $this->assertContains($res['message'], ['token_reuse', 'invalid_token', 'expired']);
        $this->assertArrayNotHasKey('refresh_token', $res);
        $this->assertSame(0, $inner->liveCountForFamily($family), 'the successor inserted after the revoke is revoked too');
    }

    public function testALogoutLandingBetweenClaimAndSuccessorInsertLeavesNoLiveToken(): void
    {
        // Same gap for revokeAllForUser (logout / password change).
        $inner = new ArrayRefreshTokenStore();
        $store = new RaceRefreshTokenStore($inner);
        $svc   = $this->svc($inner);
        $racer = new RefreshTokenService($store, ['secret' => 'k', 'expire' => 'now +15 minutes', 'refresh_expire' => 2592000, 'refresh_family_expire' => 7776000], fn () => $this->clock);
        $rt    = $svc->mintForLogin(7);
        $family = $inner->rows[1]['family_id'];
        $this->clock += 100;
        $store->beforeInsert = fn () => $svc->revokeAllForUser(7);

        $res = $racer->redeem($rt, '1.1.1.1', $this->minter());

        $this->assertSame('error', $res['status']);
        $this->assertSame(0, $inner->liveCountForFamily($family));
    }

    public function testATwiceRotatedStaleTokenGetsNoAccessTokenAfterRevoke(): void
    {
        // M-1: a->b, b->c, revoke(c), then a (inside REUSE_GRACE) must not be
        // grace-replayed into a fresh access JWT.
        $store = new ArrayRefreshTokenStore();
        $svc   = $this->svc($store);
        $a = $svc->mintForLogin(7);
        $this->clock += 5;
        $b = $svc->redeem($a, '1.1.1.1', $this->minter())['refresh_token'];
        $this->clock += 5;
        $c = $svc->redeem($b, '1.1.1.1', $this->minter())['refresh_token'];
        $this->clock += 2;
        $this->assertSame(['status' => 'success'], $svc->revokeByToken($c, '1.1.1.1'));
        $this->clock += 2;

        $res = $svc->redeem($a, '1.1.1.1', $this->minter());
        $this->assertSame('error', $res['status']);
        $this->assertArrayNotHasKey('token', $res);
        $this->assertSame('error', $svc->redeem($b, '1.1.1.1', $this->minter())['status']);
    }
}

/** Decorator: runs a hook right before the successor insert (simulates a concurrent revoke). */
final class RaceRefreshTokenStore implements RefreshTokenStore
{
    /** @var callable|null */
    public $beforeInsert = null;

    public function __construct(private RefreshTokenStore $inner) {}

    public function insert(array $row): void
    {
        if ($this->beforeInsert !== null) {
            $hook = $this->beforeInsert;
            $this->beforeInsert = null;
            $hook();
        }
        $this->inner->insert($row);
    }
    public function findByHash(string $hash): ?array { return $this->inner->findByHash($hash); }
    public function markRevoked(int $id, int $lastUsedAt): void { $this->inner->markRevoked($id, $lastUsedAt); }
    public function claimRotation(int $id, int $at): bool { return $this->inner->claimRotation($id, $at); }
    public function revokeFamily(string $familyId): void { $this->inner->revokeFamily($familyId); }
    public function revokeAllForUser(int $idAuthy): void { $this->inner->revokeAllForUser($idAuthy); }
    public function revokeAllForUserExcept(int $idAuthy, string $keepFamilyId): void { $this->inner->revokeAllForUserExcept($idAuthy, $keepFamilyId); }
    public function liveFamilies(int $idAuthy, int $now): array { return $this->inner->liveFamilies($idAuthy, $now); }
    public function recentAttemptCount(string $ip, string $familyId, int $since): int { return $this->inner->recentAttemptCount($ip, $familyId, $since); }
    public function recordAttempt(string $ip, string $familyId, int $at): void { $this->inner->recordAttempt($ip, $familyId, $at); }
}
