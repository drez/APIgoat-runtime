<?php

declare(strict_types=1);

namespace ApiGoat\Tests\OAuth;

use ApiGoat\OAuth\AuthCodeRepository;
use ApiGoat\OAuth\RefreshTokenRepository;
use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\TestCase;

/**
 * Review-3 #7 — OAuth 2.1 token races, against the REAL Propel queries of
 * the emitted \App\Oauth* models (from the host project's autoload, e.g.
 * p/test) on the project's MySQL connection, with the oauth_* tables
 * shadowed by TEMPORARY tables: the conditional UPDATE and its affected-row
 * count are exercised for real, not mocked.
 *
 * "Concurrent" redeems are simulated by the interleaving league's grants
 * produce under a race: both requests pass the read-only is*Revoked() check,
 * then both reach the revoke*() claim.
 */
final class OAuthTokenRaceTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (!class_exists('\App\OauthRefreshTokenQuery') || !class_exists('\Propel')) {
            $this->markTestSkipped('needs a project autoload with the emitted \App\Oauth* models');
        }
        // The host project's Propel conf (MySQL). All three oauth_* tables are
        // shadowed by per-connection TEMPORARY tables of the same name, so the
        // real rows are never read or written and nothing survives the test.
        $admin = \dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 3);
        $conf = $admin . '/config/Built/db.php';
        if (!is_file($conf)) {
            $this->markTestSkipped('no project config/Built/db.php');
        }
        \Propel::setConfiguration(require $conf);
        \Propel::initialize();
        \Propel::disableInstancePooling();   // every read hits the DB, like separate requests
        try {
            $this->pdo = \Propel::getConnection(\App\OauthRefreshTokenPeer::DATABASE_NAME);
        } catch (\Throwable $e) {
            $this->markTestSkipped('project DB unreachable: ' . $e->getMessage());
        }
        foreach (['oauth_refresh_token' => 'token_id', 'oauth_access_token' => 'token_id', 'oauth_auth_code' => 'code_id'] as $t => $idCol) {
            $extra = $t === 'oauth_refresh_token' ? 'access_token_id VARCHAR(128),' : 'scopes VARCHAR(191),';
            $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS $t");
            $this->pdo->exec("CREATE TEMPORARY TABLE $t (id_$t INTEGER PRIMARY KEY AUTO_INCREMENT, $idCol VARCHAR(128) NOT NULL UNIQUE,
                $extra id_authy INTEGER, client_id VARCHAR(80), expires INTEGER, revoked TINYINT DEFAULT 0,
                created_at DATETIME, date_creation DATETIME, date_modification DATETIME,
                id_group_creation INTEGER, id_creation INTEGER, id_modification INTEGER)");
        }
        \ini_set('error_log', '/dev/null');   // revokeFamily logs via error_log
        \ApiGoat\Utility\MicroCache::flushLocal();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            foreach (['oauth_refresh_token', 'oauth_access_token', 'oauth_auth_code'] as $t) {
                $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS $t");   // TEMPORARY only — never the real table
            }
        }
        if (class_exists('\Propel', false)) {
            \Propel::enableInstancePooling();
        }
    }

    private function insert(string $table, string $id, int $user = 7, string $client = 'cli', int $revoked = 0, ?int $expires = null): void
    {
        $idCol = $table === 'oauth_auth_code' ? 'code_id' : 'token_id';
        $st = $this->pdo->prepare("INSERT INTO $table ($idCol, id_authy, client_id, expires, revoked) VALUES (?,?,?,?,?)");
        $st->execute([$id, $user, $client, $expires ?? time() + 3600, $revoked]);
    }

    private function revoked(string $table, string $id): int
    {
        $idCol = $table === 'oauth_auth_code' ? 'code_id' : 'token_id';
        $st = $this->pdo->prepare("SELECT revoked FROM $table WHERE $idCol = ?");
        $st->execute([$id]);
        return (int) $st->fetchColumn();
    }

    // ---- refresh tokens ---------------------------------------------------

    public function testConcurrentRefreshSecondClaimFails(): void
    {
        $this->insert('oauth_refresh_token', 'rt1');
        $repo = new RefreshTokenRepository();

        // both racing requests pass validateOldRefreshToken()
        $this->assertFalse($repo->isRefreshTokenRevoked('rt1'));
        $this->assertFalse($repo->isRefreshTokenRevoked('rt1'));

        $repo->revokeRefreshToken('rt1');           // first claim wins
        $this->assertSame(1, $this->revoked('oauth_refresh_token', 'rt1'));

        $this->insert('oauth_refresh_token', 'rt1-succ');   // the winner's successor
        try {
            $repo->revokeRefreshToken('rt1');       // second claim loses
            $this->fail('second concurrent claim must fail');
        } catch (OAuthServerException $e) {
            $this->assertSame('invalid_request', $e->getErrorType());   // league 8.x invalidRefreshToken()
        }
        $this->assertSame(0, $this->revoked('oauth_refresh_token', 'rt1-succ'), 'losing claimant must not family-revoke');
    }

    public function testRetryWithinGraceIsRejectedWithoutFamilyRevoke(): void
    {
        $this->insert('oauth_refresh_token', 'rt');
        $repo = new RefreshTokenRepository();
        $this->assertFalse($repo->isRefreshTokenRevoked('rt'));
        $repo->revokeRefreshToken('rt');                     // rotation succeeds
        $this->insert('oauth_refresh_token', 'rt-succ');
        $this->insert('oauth_access_token', 'at-succ');

        // network-drop retry of the just-rotated token
        $this->assertTrue($repo->isRefreshTokenRevoked('rt'));
        $this->assertSame(0, $this->revoked('oauth_refresh_token', 'rt-succ'));
        $this->assertSame(0, $this->revoked('oauth_access_token', 'at-succ'));
    }

    public function testRetryAfterGraceWindowRevokesFamily(): void
    {
        $this->insert('oauth_refresh_token', 'rt');
        $repo = new RefreshTokenRepository();
        $repo->revokeRefreshToken('rt');
        $this->insert('oauth_refresh_token', 'rt-succ');
        $this->insert('oauth_access_token', 'at-succ');

        // marker expired (window passed) — or never existed (no APCu)
        \ApiGoat\Utility\MicroCache::forget(RefreshTokenRepository::rotationKey('rt'));

        $this->assertTrue($repo->isRefreshTokenRevoked('rt'));
        $this->assertSame(1, $this->revoked('oauth_refresh_token', 'rt-succ'));
        $this->assertSame(1, $this->revoked('oauth_access_token', 'at-succ'));
    }

    public function testRotationKeyIsNamespacedAndHashed(): void
    {
        $k = RefreshTokenRepository::rotationKey('secret-token-id');
        $this->assertStringContainsString(\ApiGoat\Utility\TableVersion::ns(), $k);
        $this->assertStringContainsString(hash('sha256', 'secret-token-id'), $k);
        $this->assertStringNotContainsString('secret-token-id', $k);
    }

    public function testClaimRowIsSingleWinner(): void
    {
        $this->insert('oauth_refresh_token', 'rtx');
        $wins = 0;
        for ($i = 0; $i < 5; $i++) {
            $wins += RefreshTokenRepository::claimRow(\App\OauthRefreshTokenQuery::class, 'filterByTokenId', 'rtx') ? 1 : 0;
        }
        $this->assertSame(1, $wins);
        $this->assertFalse(RefreshTokenRepository::claimRow(\App\OauthRefreshTokenQuery::class, 'filterByTokenId', 'unknown'));
        $this->assertFalse(RefreshTokenRepository::claimRow(\App\OauthRefreshTokenQuery::class, 'filterByTokenId', ''));
    }

    public function testRevokedRefreshTokenPresentedRevokesFamily(): void
    {
        $this->insert('oauth_refresh_token', 'old', 7, 'cli', 1);     // already rotated
        $this->insert('oauth_refresh_token', 'live', 7, 'cli');       // its successor
        $this->insert('oauth_access_token', 'at-live', 7, 'cli');
        $this->insert('oauth_refresh_token', 'other-client', 7, 'cli2');
        $this->insert('oauth_access_token', 'other-user', 8, 'cli');

        $this->assertTrue((new RefreshTokenRepository())->isRefreshTokenRevoked('old'));

        $this->assertSame(1, $this->revoked('oauth_refresh_token', 'live'));
        $this->assertSame(1, $this->revoked('oauth_access_token', 'at-live'));
        $this->assertSame(0, $this->revoked('oauth_refresh_token', 'other-client'), 'other client untouched');
        $this->assertSame(0, $this->revoked('oauth_access_token', 'other-user'), 'other user untouched');
    }

    public function testExpiredOrUnknownRefreshRejectedWithoutFamilyRevoke(): void
    {
        $this->insert('oauth_refresh_token', 'exp', 7, 'cli', 0, time() - 10);
        $this->insert('oauth_refresh_token', 'live', 7, 'cli');
        $repo = new RefreshTokenRepository();
        $this->assertTrue($repo->isRefreshTokenRevoked('exp'));
        $this->assertTrue($repo->isRefreshTokenRevoked('nope'));
        $this->assertSame(0, $this->revoked('oauth_refresh_token', 'live'));
    }

    // ---- auth codes -------------------------------------------------------

    public function testConcurrentAuthCodeLoserFailsAndRevokesFamily(): void
    {
        $this->insert('oauth_auth_code', 'code1');
        $repo = new AuthCodeRepository();

        $this->assertFalse($repo->isAuthCodeRevoked('code1'));
        $this->assertFalse($repo->isAuthCodeRevoked('code1'));

        // winner issued its tokens, then claims the code
        $this->insert('oauth_access_token', 'at-A', 7, 'cli');
        $this->insert('oauth_refresh_token', 'rt-A', 7, 'cli');
        $repo->revokeAuthCode('code1');
        $this->assertSame(1, $this->revoked('oauth_auth_code', 'code1'));
        $this->assertSame(0, $this->revoked('oauth_access_token', 'at-A'));

        // loser issued tokens too, then loses the claim
        $this->insert('oauth_access_token', 'at-B', 7, 'cli');
        $this->insert('oauth_refresh_token', 'rt-B', 7, 'cli');
        try {
            $repo->revokeAuthCode('code1');
            $this->fail('second concurrent redeem must fail');
        } catch (OAuthServerException $e) {
            $this->assertSame('invalid_request', $e->getErrorType());
        }
        foreach (['at-A', 'at-B'] as $t) {
            $this->assertSame(1, $this->revoked('oauth_access_token', $t), "$t revoked");
        }
        foreach (['rt-A', 'rt-B'] as $t) {
            $this->assertSame(1, $this->revoked('oauth_refresh_token', $t), "$t revoked");
        }
    }

    public function testReusedAuthCodeRevokesFamily(): void
    {
        $this->insert('oauth_auth_code', 'used', 7, 'cli', 1);
        $this->insert('oauth_access_token', 'at', 7, 'cli');
        $this->insert('oauth_refresh_token', 'rt', 7, 'cli');
        $this->insert('oauth_refresh_token', 'rt-other', 9, 'cli');

        $this->assertTrue((new AuthCodeRepository())->isAuthCodeRevoked('used'));

        $this->assertSame(1, $this->revoked('oauth_access_token', 'at'));
        $this->assertSame(1, $this->revoked('oauth_refresh_token', 'rt'));
        $this->assertSame(0, $this->revoked('oauth_refresh_token', 'rt-other'));
    }

    public function testUnknownOrExpiredAuthCodeIsRevoked(): void
    {
        $this->insert('oauth_auth_code', 'old', 7, 'cli', 0, time() - 1);
        $repo = new AuthCodeRepository();
        $this->assertTrue($repo->isAuthCodeRevoked('old'));
        $this->assertTrue($repo->isAuthCodeRevoked('nope'));
    }
}
