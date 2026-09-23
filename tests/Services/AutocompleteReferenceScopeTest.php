<?php

namespace App {
    /** A lookup target with ownership columns (like Authy / Client). */
    class ZzAcOwnedQuery
    {
        public array $calls = [];
        public static ?ZzAcOwnedQuery $last = null;
        public static function create(): self { return self::$last = new static(); }
        public function select($c) { return $this; }
        public function orderBy($c, $d = 'ASC') { return $this; }
        public function limit($n) { return $this; }
        public function filterByUsername($v) { $this->calls[] = ['like', $v]; return $this; }
        public function where($clause) { $this->calls[] = ['where', $clause]; return $this; }
        public function filterByIdTenant($v) { $this->calls[] = ['tenant', $v]; return $this; }
        public function filterByIdCreation($v) { $this->calls[] = ['owner', $v]; return $this; }
        public function filterByIdGroupCreation($v, $c = null) { $this->calls[] = ['group', $v]; return $this; }
        public function _or() { $this->calls[] = ['or']; return $this; }
        public function emptied(): bool { return in_array(['where', '1 = 0'], $this->calls, true); }
        public function find()
        {
            return $this->emptied() ? [] : [['Username' => 'alice', 'IdAuthy' => 1], ['Username' => 'bob', 'IdAuthy' => 2]];
        }
    }
}

namespace ApiGoat\Tests\Services {

    use ApiGoat\Sessions\AuthySession;
    use PHPUnit\Framework\TestCase;

    class ZzTicketService
    {
        use \ApiGoat\Services\Concerns\HandlesAutocomplete;
        public $request = [];
        public array $allow = [];
        private function autocAllowlist() { return $this->allow; }
    }

    /**
     * set_autocomplete scopes its rows with the FK dropdown's reference-access
     * rule (AuthySession::applyReferenceScope). The old inline copy returned
     * EVERY row when the caller had no 'r' on the target — any logged-in user
     * could enumerate Authy through an FK autocomplete.
     */
    final class AutocompleteReferenceScopeTest extends TestCase
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

        private function session(array $acl, bool $root = false): AuthySession
        {
            $s = new AuthySession();
            $s->set('connected', 'YES');
            $s->set('id_tenant', 7);
            $s->set('isRoot', $root);
            $s->set('id', 42);
            $s->accessControl = $acl;
            (function () { $this->Groups = [3]; })->call($s);
            return $_SESSION[_AUTH_VAR] = $s;
        }

        private function lookup(array $acl, ?array $refs, bool $root = false, array $extra = []): array
        {
            $this->session($acl, $root);
            $svc = new ZzTicketService();
            $entry = ['cols' => ['Username', 'IdAuthy'], 'ids' => ['IdAuthy'], 'where' => []];
            if ($refs !== null) {
                $entry['refs'] = $refs;
            }
            $svc->allow = ['ZzAcOwned' => $entry];
            $svc->request = ['data' => array_merge([
                'fkt' => 'ZzAcOwned', 'show' => ['Username'], 'id' => 'IdAuthy',
                'filter' => 'Username', 'str' => 'a',
            ], $extra)];
            return $svc->autocomplete();
        }

        private function ref(bool $allowed, string $form = 'ZzTicket'): array
        {
            return [['target' => 'ZzAcOwned', 'form' => $form, 'allowed' => $allowed]];
        }

        public function testNoReadAndNoWriteOnFormIsEmpty(): void
        {
            $b = $this->lookup(['ZzTicket' => ['All' => 'r']], $this->ref(true));
            self::assertSame('success', $b['status']);
            self::assertSame(0, $b['count'], 'no r on target and only r on the form: nothing');
            self::assertTrue(\App\ZzAcOwnedQuery::$last->emptied());
        }

        public function testWriterOnFormGetsTenantScopedLabels(): void
        {
            $b = $this->lookup(['ZzTicket' => ['All' => 'rw']], $this->ref(true));
            self::assertSame(2, $b['count']);
            self::assertContains(['tenant', 7], \App\ZzAcOwnedQuery::$last->calls);
        }

        public function testAuthTargetWithoutOptInStaysClosedForWriters(): void
        {
            $b = $this->lookup(['ZzTicket' => ['All' => 'rwad']], $this->ref(false));
            self::assertSame(0, $b['count']);
        }

        public function testOwnerScopedReadOnTargetKeepsNarrowing(): void
        {
            $this->lookup(['ZzTicket' => ['All' => 'rw'], 'ZzAcOwned' => ['Owner' => 'r']], $this->ref(true));
            self::assertContains(['owner', 42], \App\ZzAcOwnedQuery::$last->calls);
        }

        public function testAnyGrantingTupleWins(): void
        {
            $refs = [
                ['target' => 'ZzAcOwned', 'form' => 'Other', 'allowed' => true],
                ['target' => 'ZzAcOwned', 'form' => 'ZzTicket', 'allowed' => true],
            ];
            $b = $this->lookup(['ZzTicket' => ['All' => 'w']], $refs);
            self::assertSame(2, $b['count']);
        }

        public function testClientCannotForgeTheFormModel(): void
        {
            // request params naming another form / allowed flag are ignored
            $b = $this->lookup(['Invoice' => ['All' => 'rw']], $this->ref(true), false,
                ['form' => 'Invoice', 'allowed' => true, 'refs' => $this->ref(true, 'Invoice')]);
            self::assertSame(0, $b['count']);
        }

        public function testLegacyAllowlistDerivesFormFromTheService(): void
        {
            // allowlist baked before 'refs': form = this service's model
            $b = $this->lookup(['ZzTicket' => ['All' => 'rw']], null);
            self::assertSame(2, $b['count']);
            $b = $this->lookup(['Invoice' => ['All' => 'rw']], null);
            self::assertSame(0, $b['count']);
        }

        public function testRootIsUnscoped(): void
        {
            $b = $this->lookup([], $this->ref(false), true);
            self::assertSame(2, $b['count']);
            self::assertSame([['like', '%a%']], \App\ZzAcOwnedQuery::$last->calls);
        }
    }
}
