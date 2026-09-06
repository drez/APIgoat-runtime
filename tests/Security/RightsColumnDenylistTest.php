<?php
// Run: php tests/Security/RightsColumnDenylistTest.php
//
// Final review I-3: `rights_all` / `rights_owner` / `rights_group` — the
// `is_rights_column` family on authy / authy_group — DEFINE the ACL, and were
// still API-writable. They are real, form-visible columns, so the emitted
// per-form allowlist contains them (`new Api('Authy', $this, [… 'RightsAll',
// 'RightsGroup', 'RightsOwner' …])`) and the allowlist cannot be the gate: a
// caller holding Authy:w (or AuthyGroup:w) through an api_rbac rule could PATCH
// itself arbitrary rights — the same privilege escalation the credential /
// IsRoot denial closes, one column family over.
//
// The runtime is the single enforcement point: Api::isWritableColumn() denies
// them unconditionally, normalised (lowercase, underscores dropped) so every
// spelling hits the same entry.

namespace {
    require __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../../src/Utility/Legacy/html_helper.php'; // \camelize()
    require_once __DIR__ . '/../../src/ACL/AuthyACL.php';
    require_once __DIR__ . '/../../src/Api/Message.php';
    require_once __DIR__ . '/../../src/Api/QueryBuilder.php';
    require_once __DIR__ . '/../../src/Api/Api.php';

    use ApiGoat\Api\Api;

    class RightsProbeService {}

    // The emitted Authy allowlist shape, rights columns explicitly ON it.
    $fields = ['IdAuthy', 'Username', 'RightsAll', 'RightsOwner', 'RightsGroup', 'PasswdHash', 'IsRoot'];
    $allowlist = $fields;

    $api = new Api('authy', new RightsProbeService(), $allowlist);

    $writable = function (string $col) use ($api, $fields): bool {
        $m = new ReflectionMethod($api, 'isWritableColumn');
        $m->setAccessible(true);
        return (bool) $m->invoke($api, $col, $fields);
    };

    $fail = 0;
    $check = function (string $label, bool $cond) use (&$fail) {
        echo ($cond ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
        if (!$cond) { $fail++; }
    };

    // Every spelling the reviewer's probe used.
    foreach (['RightsAll', 'RightsOwner', 'RightsGroup'] as $php) {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $php));
        $camel = lcfirst($php);
        $check("{$php} denied (PhpName)", !$writable($php));
        $check("{$snake} denied (snake_case)", !$writable($snake));
        $check("{$camel} denied (camelCase)", !$writable($camel));
    }

    $check('an ordinary column on the allowlist is still writable', $writable('Username'));
    $check('credential columns are still denied (unchanged)', !$writable('PasswdHash'));
    $check('IsRoot is still denied (unchanged)', !$writable('IsRoot'));

    $check('RIGHTS_COLUMNS is normalised (lowercase, no underscores)',
        Api::RIGHTS_COLUMNS === ['rightsall', 'rightsowner', 'rightsgroup']);

    // The denial does not depend on the allowlist being present.
    $noAllowlist = new Api('authy', new RightsProbeService());
    $m = new ReflectionMethod($noAllowlist, 'isWritableColumn');
    $m->setAccessible(true);
    $check('denied with no allowlist at all', !(bool) $m->invoke($noAllowlist, 'RightsAll', $fields));

    // A column that merely STARTS with "rights" is not swept in.
    $fields2 = array_merge($fields, ['RightsNote']);
    $api2 = new Api('authy', new RightsProbeService(), $fields2);
    $m2 = new ReflectionMethod($api2, 'isWritableColumn');
    $m2->setAccessible(true);
    $check('RightsNote (not an ACL column) stays writable', (bool) $m2->invoke($api2, 'RightsNote', $fields2));

    echo PHP_EOL . ($fail ? "$fail FAILED" : 'all passed') . PHP_EOL;
    exit($fail ? 1 : 0);
}
