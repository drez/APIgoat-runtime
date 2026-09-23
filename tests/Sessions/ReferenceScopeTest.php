<?php

namespace ApiGoat\Tests\Sessions;

use ApiGoat\Sessions\AuthySession;
use ApiGoat\Utility\SelectBoxCache;
use PHPUnit\Framework\TestCase;

/** Records the filter calls a scope helper makes (lookup-scope flavour). */
class RefFakeQuery
{
    public array $calls = [];

    public static function create(): self
    {
        return new static();
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

/**
 * Owner decision 2026-09-23 — "reference access" for FK lookups:
 *  1. w/a on the FORM's model lists the target's rows without 'r' on the
 *     target (tenant-scoped; an Owner/Group 'r' on the target keeps narrowing);
 *     no w/a → fail closed as before.
 *  2. auth / group targets are excluded unless the column opted in — the
 *     emitter passes that decision as $referenceAllowed.
 *  4. the FK save guard (loadReferenceScoped) accepts exactly that set.
 *  5. SelectBoxCache keys the new scope apart from all/none/owner/group, with
 *     the tenant.
 */
final class ReferenceScopeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('_AUTH_VAR')) {
            define('_AUTH_VAR', 'gc_test_auth');
        }
    }

    protected function tearDown(): void
    {
        unset($_SESSION[_AUTH_VAR]);
    }

    /** @param array<string,array<string,string>> $acl model => [group => rights] */
    private function session(array $acl = [], $tenant = 7, bool $root = false): AuthySession
    {
        $s = new AuthySession();
        $s->set('connected', 'YES');
        $s->set('id_tenant', $tenant);
        $s->set('isRoot', $root);
        $s->set('id', 42);
        $s->accessControl = $acl;
        (function () { $this->Groups = [3, 9]; })->call($s);
        return $s;
    }

    public function testWriterOnFormListsTargetWithoutReadRight(): void
    {
        $q = new RefFakeQuery();
        $this->session(['Product' => ['All' => 'rw']])->applyReferenceScope($q, 'Category', 'Product', true);
        self::assertSame([['tenant', 7]], $q->calls, 'tenant-scoped, not emptied');
    }

    public function testAddRightAloneAlsoGrantsReference(): void
    {
        $q = new RefFakeQuery();
        $this->session(['Product' => ['Owner' => 'a']])->applyReferenceScope($q, 'Category', 'Product', true);
        self::assertFalse($q->emptied());
    }

    public function testReadOnlyOnFormStaysFailClosed(): void
    {
        $q = new RefFakeQuery();
        $this->session(['Product' => ['All' => 'r']])->applyReferenceScope($q, 'Category', 'Product', true);
        self::assertTrue($q->emptied());
    }

    public function testOwnerScopedReadOnTargetKeepsItsNarrowing(): void
    {
        $q = new RefFakeQuery();
        $this->session(['Product' => ['All' => 'rw'], 'Category' => ['Owner' => 'r']])
            ->applyReferenceScope($q, 'Category', 'Product', true);
        self::assertSame([['tenant', 7], ['owner', 42]], $q->calls);
    }

    public function testFullReadOnTargetIsTenantOnly(): void
    {
        $q = new RefFakeQuery();
        $this->session(['Category' => ['All' => 'r']])->applyReferenceScope($q, 'Category', 'Product', false);
        self::assertSame([['tenant', 7]], $q->calls, 'own r right needs no reference access');
    }

    public function testExcludedAuthTargetStaysFailClosedForWriters(): void
    {
        $q = new RefFakeQuery();
        $this->session(['Quote' => ['All' => 'rwad']])->applyReferenceScope($q, 'Authy', 'Quote', false);
        self::assertTrue($q->emptied(), 'auth target without opt-in');

        $q = new RefFakeQuery();
        $this->session(['Quote' => ['All' => 'rwad']])->applyReferenceScope($q, 'Authy', 'Quote', true);
        self::assertFalse($q->emptied(), 'auth target with opt-in');
    }

    public function testEmptyTenantFailsClosedEvenWithReferenceAccess(): void
    {
        $q = new RefFakeQuery();
        $this->session(['Product' => ['All' => 'rw']], null)->applyReferenceScope($q, 'Category', 'Product', true);
        self::assertTrue($q->emptied());
    }

    public function testTargetWithoutOwnershipIsTenantOnly(): void
    {
        $q = new RefFakeQuery();
        $this->session([])->applyReferenceScope($q, '', 'Product', true);
        self::assertSame([['tenant', 7]], $q->calls);
    }

    public function testRootIsUntouched(): void
    {
        $q = new RefFakeQuery();
        $this->session([], null, true)->applyReferenceScope($q, 'Authy', 'Quote', false);
        self::assertSame([], $q->calls);
    }

    public function testLoadReferenceScopedMatchesTheDropdown(): void
    {
        $writer = $this->session(['Product' => ['All' => 'rw']]);
        self::assertNotNull($writer->loadReferenceScoped(RefFakeQuery::class, 5, 'Category', 'Product', true));
        self::assertNull($writer->loadReferenceScoped(RefFakeQuery::class, 5, 'Authy', 'Product', false));
        self::assertNull($this->session(['Product' => ['All' => 'r']])
            ->loadReferenceScoped(RefFakeQuery::class, 5, 'Category', 'Product', true));
        // full-row load stays fail-closed for the same writer
        self::assertNull($writer->loadPkScoped(RefFakeQuery::class, 5, 'Category', 'r'));
    }

    public function testCacheTokenDistinguishesEveryScope(): void
    {
        $_SESSION[_AUTH_VAR] = $this->session(['Product' => ['All' => 'rw']]);
        self::assertSame('ref@t7', SelectBoxCache::referenceScopeToken('Category', 'Product', true));
        self::assertSame('none@t7', SelectBoxCache::referenceScopeToken('Authy', 'Product', false));
        self::assertSame('all@t7', SelectBoxCache::referenceScopeToken('', 'Product', true));

        $_SESSION[_AUTH_VAR] = $this->session(['Category' => ['All' => 'r']]);
        self::assertSame('all@t7', SelectBoxCache::referenceScopeToken('Category', 'Product', true));

        $_SESSION[_AUTH_VAR] = $this->session(['Category' => ['Owner' => 'r', 'Group' => 'r']]);
        self::assertSame('o42-g3.9@t7', SelectBoxCache::referenceScopeToken('Category', 'Product', true));

        $_SESSION[_AUTH_VAR] = $this->session(['Product' => ['All' => 'r']]);
        self::assertSame('none@t7', SelectBoxCache::referenceScopeToken('Category', 'Product', true));

        $_SESSION[_AUTH_VAR] = $this->session(['Category' => ['All' => 'r']], null);
        self::assertSame('all@t-', SelectBoxCache::referenceScopeToken('Category', 'Product', true),
            'an empty-tenant user must not share root\'s entry');

        $_SESSION[_AUTH_VAR] = $this->session(['Product' => ['All' => 'rw']], 8);
        self::assertSame('ref@t8', SelectBoxCache::referenceScopeToken('Category', 'Product', true));

        $_SESSION[_AUTH_VAR] = $this->session([], null, true);
        self::assertSame('all', SelectBoxCache::referenceScopeToken('Authy', 'Quote', false));
    }

    public function testKeepStoredOptionAppendsAMissingValueWithItsLabel(): void
    {
        $opts = [['', ''], ['Books', 1]];
        $calls = 0;
        $load = function ($v) use (&$calls) { $calls++; return ['selDisplay' => 'Secret shelf', 'IdCategory' => $v]; };

        self::assertSame($opts, SelectBoxCache::keepStoredOption($opts, 1, $load), 'listed value unchanged');
        self::assertSame($opts, SelectBoxCache::keepStoredOption($opts, '1', $load), 'string/int agree');
        self::assertSame($opts, SelectBoxCache::keepStoredOption($opts, null, $load));
        self::assertSame($opts, SelectBoxCache::keepStoredOption($opts, '', $load));
        self::assertSame(0, $calls, 'no lookup when nothing is missing');

        $out = SelectBoxCache::keepStoredOption($opts, 12, $load);
        self::assertSame([['', ''], ['Books', 1], ['Secret shelf', 12]], $out);

        $out = SelectBoxCache::keepStoredOption($opts, 13, fn($v) => null);
        self::assertSame(['#13', 13], $out[2], 'unresolvable value still kept');
    }
}
