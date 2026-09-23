<?php

namespace ApiGoat\Tests\Sessions;

use ApiGoat\Sessions\AuthySession;
use PHPUnit\Framework\TestCase;

/** Records the filter calls a scope helper makes. */
class ScopeFakeQuery
{
    public array $calls = [];
    public static ?ScopeFakeQuery $last = null;

    public static function create(): self
    {
        return self::$last = new static();
    }
    public function where($clause) { $this->calls[] = ['where', $clause]; return $this; }
    public function filterByIdTenant($v) { $this->calls[] = ['tenant', $v]; return $this; }
    public function filterByIdCreation($v) { $this->calls[] = ['owner', $v]; return $this; }
    public function filterByIdGroupCreation($v, $c = null) { $this->calls[] = ['group', $v]; return $this; }
    public function _or() { $this->calls[] = ['or']; return $this; }
    public function filterByPrimaryKey($pk) { $this->calls[] = ['pk', $pk]; return $this; }
    public function findOne() { return $this->emptied() ? null : (object) ['pk' => 1]; }
    public function emptied(): bool { return in_array(['where', '1 = 0'], $this->calls, true); }
}

/** A model without an id_tenant column. */
class ScopeFakeUntenantedQuery
{
    public array $calls = [];
    public function where($clause) { $this->calls[] = ['where', $clause]; return $this; }
}

class ScopeAclHost
{
    use \ApiGoat\ACL\AuthyACL;
    public function setGroupScope($g): void { $this->aclGroup = $g; }
}

/**
 * Review 2026-09-23 (owner decision: FAIL CLOSED).
 *  - applyOwnerGroupScope(false) returned the query unscoped: every row.
 *  - an empty session id_tenant skipped the tenant partition entirely.
 * Root and anonymous (not connected) sessions keep their behaviour.
 */
final class ScopeFailClosedTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('_AUTH_VAR')) {
            define('_AUTH_VAR', 'gc_test_auth');
        }
    }

    private function session(bool $connected = true, $tenant = 7, bool $root = false): AuthySession
    {
        $s = new AuthySession();
        $s->set('connected', $connected ? 'YES' : 'NO');
        $s->set('id_tenant', $tenant);
        $s->set('isRoot', $root);
        $s->set('id', 42);
        return $s;
    }

    public function testNoGrantFailsClosed(): void
    {
        $q = new ScopeFakeQuery();
        $this->session()->applyOwnerGroupScope($q, false);
        self::assertTrue($q->emptied());
    }

    public function testUnrestrictedGrantStaysUnscoped(): void
    {
        $q = new ScopeFakeQuery();
        $this->session()->applyOwnerGroupScope($q, true);
        self::assertSame([], $q->calls);
    }

    public function testOwnerScopeStillNarrows(): void
    {
        $q = new ScopeFakeQuery();
        $this->session()->applyOwnerGroupScope($q, ['Owner']);
        self::assertSame([['owner', 42]], $q->calls);
    }

    public function testRootWithoutAGrantIsNotEmptied(): void
    {
        $q = new ScopeFakeQuery();
        $this->session(true, null, true)->applyOwnerGroupScope($q, false);
        self::assertSame([], $q->calls);
    }

    public function testAdminGroupHasRightsIsTrue(): void
    {
        $s = $this->session();
        (function () { $this->group = 'Admin'; })->call($s);
        self::assertTrue($s->hasRights('Anything', 'r'));
    }

    public function testTenantScopeFiltersByTheSessionTenant(): void
    {
        $q = new ScopeFakeQuery();
        $this->session(true, 7)->applyTenantScope($q);
        self::assertSame([['tenant', 7]], $q->calls);
    }

    public function testConnectedUserWithEmptyTenantFailsClosed(): void
    {
        foreach ([null, 0, '', '0'] as $empty) {
            $q = new ScopeFakeQuery();
            $this->session(true, $empty)->applyTenantScope($q);
            self::assertTrue($q->emptied(), var_export($empty, true));
            self::assertFalse($this->session(true, $empty)->canStampTenant());
        }
    }

    public function testAnonymousAndRootAndUntenantedModelsAreUntouched(): void
    {
        $q = new ScopeFakeQuery();
        $this->session(false, null)->applyTenantScope($q);
        self::assertSame([], $q->calls, 'anonymous');
        self::assertTrue($this->session(false, null)->canStampTenant());

        $q = new ScopeFakeQuery();
        $this->session(true, null, true)->applyTenantScope($q);
        self::assertSame([], $q->calls, 'root');
        self::assertTrue($this->session(true, null, true)->canStampTenant());

        $u = new ScopeFakeUntenantedQuery();
        $this->session(true, null)->applyTenantScope($u);
        self::assertSame([], $u->calls, 'model without id_tenant');
    }

    public function testLoadPkScopedWithEmptyTenantReturnsNothing(): void
    {
        $s = $this->session(true, null);
        self::assertNull($s->loadPkScoped(ScopeFakeQuery::class, 1));
        self::assertNotNull($this->session(true, 7)->loadPkScoped(ScopeFakeQuery::class, 1));
    }

    public function testLoadPkScopedWithoutAGrantReturnsNothing(): void
    {
        // no accessControl entry for 'Client' → hasRights false → fail closed
        self::assertNull($this->session(true, 7)->loadPkScoped(ScopeFakeQuery::class, 1, 'Client', 'r'));
        self::assertNotNull($this->session(true, null, true)->loadPkScoped(ScopeFakeQuery::class, 1, 'Client', 'r'));
    }

    public function testSetAclFilterAppliesBothFailClosedRules(): void
    {
        $_SESSION[_AUTH_VAR] = $this->session(true, null);
        $host = new ScopeAclHost();
        $q = new ScopeFakeQuery();
        $host->setAclFilter($q);
        self::assertTrue($q->emptied(), 'empty tenant');

        $_SESSION[_AUTH_VAR] = $this->session(true, 7);
        $host->setGroupScope(false);
        $q = new ScopeFakeQuery();
        $host->setAclFilter($q);
        self::assertSame([['tenant', 7], ['where', '1 = 0']], $q->calls, 'no grant');

        // authorize() never ran (Public read): no Owner/Group scope
        $host = new ScopeAclHost();
        $q = new ScopeFakeQuery();
        $host->setAclFilter($q);
        self::assertSame([['tenant', 7]], $q->calls);
        unset($_SESSION[_AUTH_VAR]);
    }
}
