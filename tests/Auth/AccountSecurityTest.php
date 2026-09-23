<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\AccountSecurity;
use ApiGoat\Auth\SessionLifetime;
use ApiGoat\Sessions\AuthySession;
use ApiGoat\Utility\MicroCache;
use PHPUnit\Framework\TestCase;

/** An authy row with (or without) the session_epoch column. */
class EpochFakeAuthy
{
    public array $v = [
        'IdAuthy' => 42, 'RightsAll' => '{}', 'RightsOwner' => '{}', 'RightsGroup' => '{}',
        'IsRoot' => 'No', 'IdTenant' => 1, 'IdAuthyGroup' => 3, 'Deactivate' => 'No', 'Expire' => null,
        'SessionEpoch' => 0, 'PasswdHash' => '', 'Username' => 'bob',
    ];
    public int $saves = 0;
    public function getIdAuthy() { return $this->v['IdAuthy']; }
    public function getRightsAll() { return $this->v['RightsAll']; }
    public function getRightsOwner() { return $this->v['RightsOwner']; }
    public function getRightsGroup() { return $this->v['RightsGroup']; }
    public function getIsRoot() { return $this->v['IsRoot']; }
    public function getIdTenant() { return $this->v['IdTenant']; }
    public function getIdAuthyGroup() { return $this->v['IdAuthyGroup']; }
    public function getDeactivate() { return $this->v['Deactivate']; }
    public function getExpire() { return $this->v['Expire']; }
    public function getSessionEpoch() { return $this->v['SessionEpoch']; }
    public function setSessionEpoch($e) { $this->v['SessionEpoch'] = $e; }
    public function getPasswdHash() { return $this->v['PasswdHash']; }
    public function getUsername() { return $this->v['Username']; }
    public function save() { $this->saves++; }
}

/**
 * Review 3, Wave 1 #5 (session epoch) and #9 (account re-auth throttle).
 */
final class AccountSecurityTest extends TestCase
{
    private const GROUPS = ['members' => [], 'primary_admin' => 'No'];

    public static function setUpBeforeClass(): void
    {
        if (!defined('_AUTH_VAR')) {
            define('_AUTH_VAR', 'gc_test_auth');
        }
        if (!defined('_BASE_DIR')) {
            $dir = sys_get_temp_dir() . '/acctsec-' . bin2hex(random_bytes(4)) . '/';
            mkdir($dir . 'config', 0775, true);
            define('_BASE_DIR', $dir);
        }
        $perm = rtrim((string) _BASE_DIR, '/') . '/config/permissions.php';
        if (!is_file($perm)) {
            @mkdir(dirname($perm), 0775, true);
            @file_put_contents($perm, '<?php $omMap = [];');
        }
    }

    protected function setUp(): void
    {
        MicroCache::flushLocal();
        unset($_SESSION[_AUTH_VAR]);
    }

    private function session(int $id = 42, ?int $epoch = null): AuthySession
    {
        $s = new AuthySession();
        $s->set('connected', 'YES');
        $s->set('id', $id);
        $s->set('session_epoch', $epoch);
        $s->setCsrf('tok123');
        $s->lang = 'fr_CA';
        return $s;
    }

    // ---- epoch primitives

    public function testEpochOfNeedsTheColumn(): void
    {
        $a = new EpochFakeAuthy();
        $a->v['SessionEpoch'] = '77';
        self::assertSame(77, AccountSecurity::epochOf($a));
        self::assertNull(AccountSecurity::epochOf(new \stdClass()));
        self::assertNull(AccountSecurity::epochOf(null));
    }

    public function testNewEpochNeverRepeatsTheCurrentOne(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $e = AccountSecurity::newEpoch(5);
            self::assertNotSame(5, $e);
            self::assertGreaterThan(0, $e);
            self::assertLessThanOrEqual(2147483647, $e);
        }
    }

    public function testBumpEpochSavesAndPublishes(): void
    {
        $a = new EpochFakeAuthy();
        $e = AccountSecurity::bumpEpoch($a);
        self::assertSame($e, $a->v['SessionEpoch']);
        self::assertSame(1, $a->saves);
        self::assertSame($e, AccountSecurity::epochMarker(42));

        $e2 = AccountSecurity::bumpEpoch($a, false);
        self::assertNotSame($e, $e2);
        self::assertSame(1, $a->saves, 'save=false leaves the save to the caller');
        self::assertNull(AccountSecurity::bumpEpoch(new \stdClass()), 'no column: no-op');
    }

    public function testMarkerIsProjectNamespaced(): void
    {
        self::assertStringContainsString(\ApiGoat\Utility\TableVersion::ns(), AccountSecurity::markerKey(9));
    }

    public function testPublishedMarkerFlagsOlderSessionsOnly(): void
    {
        self::assertFalse(AccountSecurity::sessionEpochStale($this->session(42, 10)), 'no marker: no forced check');
        AccountSecurity::publishEpoch(42, 11);
        self::assertTrue(AccountSecurity::sessionEpochStale($this->session(42, 10)));
        self::assertTrue(AccountSecurity::sessionEpochStale($this->session(42, null)), 'unknown epoch: check');
        self::assertFalse(AccountSecurity::sessionEpochStale($this->session(42, 11)));
        self::assertFalse(AccountSecurity::sessionEpochStale($this->session(43, 10)), 'per user');
    }

    public function testTokenEpochClaim(): void
    {
        $s = $this->session(42, 11);
        self::assertTrue(AccountSecurity::tokenEpochMismatch(['sep' => 10], $s));
        self::assertFalse(AccountSecurity::tokenEpochMismatch(['sep' => 11], $s));
        self::assertFalse(AccountSecurity::tokenEpochMismatch(['authyId' => 42], $s), 'token minted before the claim');
        self::assertFalse(AccountSecurity::tokenEpochMismatch(null, $s));
        self::assertFalse(AccountSecurity::tokenEpochMismatch(['sep' => 10], $this->session(42, null)), 'no column');
    }

    // ---- revalidation

    public function testRevalidateLogsOutOnEpochChange(): void
    {
        $a = new EpochFakeAuthy();
        $a->v['SessionEpoch'] = 10;
        $s = $this->session(42, 10);
        $first = $s->revalidate($a, self::GROUPS);
        self::assertNotSame('logout', $first);
        self::assertSame('ok', $s->revalidate($a, self::GROUPS));
        $a->v['SessionEpoch'] = 99;
        self::assertSame('logout', $s->revalidate($a, self::GROUPS));
    }

    public function testRevalidateAdoptsEpochForPreColumnSessions(): void
    {
        $a = new EpochFakeAuthy();
        $a->v['SessionEpoch'] = 10;
        $s = $this->session(42, null);
        self::assertNotSame('logout', $s->revalidate($a, self::GROUPS), 'a pre-deploy session is not locked out');
        self::assertSame(10, $s->get('session_epoch'));
        $a->v['SessionEpoch'] = 12;
        self::assertSame('logout', $s->revalidate($a, self::GROUPS), '...but a later re-roll ends it');
    }

    public function testRevalidateWithoutColumnNeverLogsOut(): void
    {
        $s = $this->session(42, 10);
        self::assertNotSame('logout', $s->revalidate($this->withoutEpochMethods(), self::GROUPS),
            'an old DB (no session_epoch yet) must not lock anyone out');
        self::assertSame(10, $s->get('session_epoch'));
    }

    /** An authy double whose class has no session-epoch accessors at all. */
    private function withoutEpochMethods(): object
    {
        return new class {
            public function getIdAuthy() { return 42; }
            public function getRightsAll() { return '{}'; }
            public function getRightsOwner() { return '{}'; }
            public function getRightsGroup() { return '{}'; }
            public function getIsRoot() { return 'No'; }
            public function getIdAuthyGroup() { return 3; }
            public function getDeactivate() { return 'No'; }
            public function getExpire() { return null; }
        };
    }

    public function testEpochSurvivesSessionSerialization(): void
    {
        $s = $this->session(42, 1234);
        $r = unserialize(serialize($s));
        self::assertSame(1234, $r->get('session_epoch'));
    }

    // ---- signed-out session

    public function testSignedOutSessionKeepsOnlyBrowserState(): void
    {
        $prev = $this->session(42, 5);
        $out = AccountSecurity::signedOutSession($prev);
        self::assertSame('NO', $out->get('connected'));
        self::assertSame('tok123', $out->getCsrf(), 're-auth modal can still post its token');
        self::assertSame('fr_CA', $out->lang);
        self::assertNull($out->getIdAuthy());
        self::assertNull($out->get('session_epoch'));
    }

    // ---- re-auth throttle (#9)

    public function testReauthVerifiesAndThrottles(): void
    {
        $a = new EpochFakeAuthy();
        $a->v['PasswdHash'] = password_hash('right-pass', PASSWORD_BCRYPT, ['cost' => 4]);
        $_SESSION[_AUTH_VAR] = $this->session(42, 0);

        self::assertTrue(AccountSecurity::verifyCurrentPassword($a, 'right-pass'));
        self::assertFalse(AccountSecurity::verifyCurrentPassword($a, ''));
        for ($i = 1; $i < AccountSecurity::REAUTH_MAX_FAILURES; $i++) {
            self::assertFalse(AccountSecurity::verifyCurrentPassword($a, 'wrong-' . $i));
            self::assertSame('YES', $_SESSION[_AUTH_VAR]->get('connected'), 'still signed in after ' . $i);
        }
        self::assertFalse(AccountSecurity::verifyCurrentPassword($a, 'wrong-last'));
        self::assertSame('NO', $_SESSION[_AUTH_VAR]->get('connected'), 'the limit signs the asking session out');
        self::assertSame('tok123', $_SESSION[_AUTH_VAR]->getCsrf());

        // Throttled: even the right password is refused, indistinguishably.
        self::assertFalse(AccountSecurity::verifyCurrentPassword($a, 'right-pass'));
        self::assertGreaterThanOrEqual(AccountSecurity::REAUTH_MAX_FAILURES, AccountSecurity::reauthFailures(42));

        // Per user: another account is unaffected.
        $b = new EpochFakeAuthy();
        $b->v['IdAuthy'] = 43;
        $b->v['PasswdHash'] = $a->v['PasswdHash'];
        self::assertTrue(AccountSecurity::verifyCurrentPassword($b, 'right-pass'));
    }

    public function testThrottleDoesNotSignOutAnotherUsersSession(): void
    {
        $a = new EpochFakeAuthy();
        $a->v['PasswdHash'] = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
        $_SESSION[_AUTH_VAR] = $this->session(7, 0);
        for ($i = 0; $i <= AccountSecurity::REAUTH_MAX_FAILURES; $i++) {
            AccountSecurity::verifyCurrentPassword($a, 'nope');
        }
        self::assertSame('YES', $_SESSION[_AUTH_VAR]->get('connected'));
    }

    // ---- own password change (#5 + #9)

    public function testPasswordChangeKeepsTheCurrentSessionOnTheNewEpoch(): void
    {
        $a = new EpochFakeAuthy();
        $a->v['SessionEpoch'] = 500; // re-rolled by the model preSave on save
        $_SESSION[_AUTH_VAR] = $this->session(42, 400);
        AccountSecurity::passwordChanged($a);
        self::assertSame(500, $_SESSION[_AUTH_VAR]->get('session_epoch'));
        self::assertSame('YES', $_SESSION[_AUTH_VAR]->get('connected'));
        self::assertSame(500, AccountSecurity::epochMarker(42));
        self::assertSame(0, $a->saves, 'the preSave already re-rolled it');
        self::assertFalse(AccountSecurity::sessionEpochStale($_SESSION[_AUTH_VAR]));
        self::assertNotSame('logout', $_SESSION[_AUTH_VAR]->revalidate($a, self::GROUPS));
    }

    public function testPasswordChangeWithoutPreSaveBumpStillReRolls(): void
    {
        $a = new EpochFakeAuthy();
        $a->v['SessionEpoch'] = 400;
        $_SESSION[_AUTH_VAR] = $this->session(42, 400);
        AccountSecurity::passwordChanged($a);
        self::assertNotSame(400, $a->v['SessionEpoch']);
        self::assertSame(1, $a->saves);
        self::assertSame($a->v['SessionEpoch'], $_SESSION[_AUTH_VAR]->get('session_epoch'));
        // Another session of the same user opened under 400 is now stale.
        self::assertTrue(AccountSecurity::sessionEpochStale($this->session(42, 400)));
    }

    // ---- EXTRA: API token exchange owns no cookie session

    public function testApiTokenExchangeIsRecognised(): void
    {
        $post = fn (string $uri) => ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $uri];
        self::assertTrue(SessionLifetime::isApiTokenExchange($post('/api/v1/Authy/auth')));
        self::assertTrue(SessionLifetime::isApiTokenExchange($post('/p/test/api/v1/Authy/refresh?x=1')));
        self::assertFalse(SessionLifetime::isApiTokenExchange($post('/p/test/Authy/auth')), 'browser login keeps its session');
        self::assertFalse(SessionLifetime::isApiTokenExchange(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/Authy/auth']));
        self::assertFalse(SessionLifetime::isApiTokenExchange($post('/api/v1/Authy/authx')));
        self::assertFalse(SessionLifetime::isApiTokenExchange($post('/api/v1/Invoice/auth/5')));
    }
}
