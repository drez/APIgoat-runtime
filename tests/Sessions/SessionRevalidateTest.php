<?php

namespace ApiGoat\Tests\Sessions;

use ApiGoat\Sessions\AuthySession;
use PHPUnit\Framework\TestCase;

/** The authy row getters revalidation reads. */
class RevalFakeAuthy
{
    public array $v = [
        'IdAuthy' => 42, 'RightsAll' => '{"Invoice":"rw"}', 'RightsOwner' => '{}', 'RightsGroup' => '{}',
        'IsRoot' => 'No', 'IdTenant' => 7, 'IdAuthyGroup' => 3, 'Deactivate' => 'No', 'Expire' => null,
    ];
    public function getIdAuthy() { return $this->v['IdAuthy']; }
    public function getRightsAll() { return $this->v['RightsAll']; }
    public function getRightsOwner() { return $this->v['RightsOwner']; }
    public function getRightsGroup() { return $this->v['RightsGroup']; }
    public function getIsRoot() { return $this->v['IsRoot']; }
    public function getIdTenant() { return $this->v['IdTenant']; }
    public function getIdAuthyGroup() { return $this->v['IdAuthyGroup']; }
    public function getDeactivate() { return $this->v['Deactivate']; }
    public function getExpire() { return $this->v['Expire']; }
}

/**
 * GUI session revalidation (AuthyMiddleware stale check): the check used to
 * only confirm the authy row still existed, so a deactivated / expired user
 * kept browsing and a revoked right or admin group kept applying until the
 * session expired. setRights()/setGroups() also only appended, so even a
 * rebuild could not revoke anything.
 */
final class SessionRevalidateTest extends TestCase
{
    private const GROUPS = ['members' => [[9, 'No']], 'primary_admin' => 'No'];

    public static function setUpBeforeClass(): void
    {
        if (!defined('_AUTH_VAR')) {
            define('_AUTH_VAR', 'gc_test_auth');
        }
        if (!defined('_BASE_DIR')) {
            $dir = sys_get_temp_dir() . '/reval-' . bin2hex(random_bytes(4)) . '/';
            mkdir($dir . 'config', 0775, true);
            define('_BASE_DIR', $dir);
        }
        $perm = rtrim((string) _BASE_DIR, '/') . '/config/permissions.php';
        if (!is_file($perm)) {
            if (!is_dir(dirname($perm))) {
                @mkdir(dirname($perm), 0775, true);
            }
            @file_put_contents($perm, '<?php $omMap = [];');
        }
    }

    private function live(RevalFakeAuthy $a, array $groups = self::GROUPS): AuthySession
    {
        $s = new AuthySession();
        $s->set('connected', 'YES');
        $s->set('id', 42);
        self::assertSame('refreshed', $s->revalidate($a, $groups), 'first check builds + fingerprints');
        return $s;
    }

    public function testUnchangedRowIsOk(): void
    {
        $a = new RevalFakeAuthy();
        $s = $this->live($a);
        self::assertSame('ok', $s->revalidate($a, self::GROUPS));
        self::assertTrue($s->hasRights('Invoice', 'w'));
        self::assertSame([9, 3], $s->getGroups());
    }

    public function testRevokedRightDisappears(): void
    {
        $a = new RevalFakeAuthy();
        $s = $this->live($a);
        $a->v['RightsAll'] = '{"Invoice":"r"}';
        self::assertSame('refreshed', $s->revalidate($a, self::GROUPS));
        self::assertFalse($s->hasRights('Invoice', 'w'), 'setRights rebuilds, never merges');
        self::assertTrue($s->hasRights('Invoice', 'r'));
    }

    public function testRemovedAdminGroupDropsAdmin(): void
    {
        $a = new RevalFakeAuthy();
        $s = $this->live($a, ['members' => [[9, 'Yes']], 'primary_admin' => 'No']);
        self::assertTrue($s->isAdmin());
        self::assertSame('refreshed', $s->revalidate($a, ['members' => [], 'primary_admin' => 'No']));
        self::assertFalse($s->isAdmin());
        self::assertSame([3], $s->getGroups(), 'setGroups rebuilds, never appends');
    }

    public function testRootAndTenantChangesRefresh(): void
    {
        $a = new RevalFakeAuthy();
        $a->v['IsRoot'] = 'Yes';
        $s = $this->live($a);
        self::assertTrue($s->isRoot());
        $a->v['IsRoot'] = 'No';
        $a->v['IdTenant'] = 8;
        self::assertSame('refreshed', $s->revalidate($a, self::GROUPS));
        self::assertFalse($s->isRoot());
        self::assertSame(8, $s->get('id_tenant'));
    }

    public function testDeactivatedOrExpiredLogsOut(): void
    {
        $a = new RevalFakeAuthy();
        $s = $this->live($a);
        $a->v['Deactivate'] = 'Yes';
        self::assertSame('logout', $s->revalidate($a, self::GROUPS));

        $a->v['Deactivate'] = 'No';
        $a->v['Expire'] = date('Y-m-d');
        self::assertSame('logout', $s->revalidate($a, self::GROUPS), 'expire today = expired (login rule)');

        $a->v['Expire'] = date('Y-m-d', strtotime('+2 days'));
        self::assertSame('ok', $s->revalidate($a, self::GROUPS));
    }

    public function testStaleCheckTimestampRoundTrips(): void
    {
        // get('stale_check_ts') used to be unsupported: the per-minute GET
        // throttle never engaged (every request hit the DB).
        $s = new AuthySession();
        $s->set('stale_check_ts', 1234);
        self::assertSame(1234, $s->get('stale_check_ts'));
    }

    public function testMiddlewareUsesRevalidate(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Middlewares/AuthyMiddleware.php');
        self::assertStringContainsString('->revalidate($gcAuthy, AuthySession::loadGroupState($gcAuthy))', $src);
        self::assertStringContainsString("if (\$gcVerdict !== 'ok') {\n                    \$access = \$this->checkPrivileges(\$request);", $src);
    }
}
