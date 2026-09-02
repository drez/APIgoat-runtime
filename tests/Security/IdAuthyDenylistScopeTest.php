<?php
// Run: php tests/Security/IdAuthyDenylistScopeTest.php
//
// Regression guard for the IdAuthy entry on Api::SYSTEM_COLUMNS (commit
// 753f496). That denylist exists so an API/JWT client can never RELINK a
// with_authy_user host row (e.g. a Learner) to an arbitrary login. But the
// entry was applied to EVERY table, and on tables where id_authy is an
// ordinary "which user" FK exposed on the form (apigoatacc time_line /
// transportation, both sitting behind the MCP accx_add_time / accx_add_transport
// tools) the generic create path silently dropped IdAuthy from the INSERT and
// the FK constraint failed. The denylist is now scoped: IdAuthy is denied iff
// the service handed to Api carries the with_authy_user hooks
// (gcAuthyUserSync / gcAuthyUserProvision), and fail-closed when there is no
// service to inspect.

namespace App {
    class TimeLineQuery {}
}

namespace {
    require __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../../src/Utility/Legacy/html_helper.php'; // \camelize()
    require_once __DIR__ . '/../../src/ACL/AuthyACL.php';
    require_once __DIR__ . '/../../src/Api/Message.php';
    require_once __DIR__ . '/../../src/Api/QueryBuilder.php';
    require_once __DIR__ . '/../../src/Api/Api.php';

    use ApiGoat\Api\Api;

    /** A plain generated service: id_authy is a form-editable FK. */
    class PlainService {}

    /** A with_authy_user host service: id_authy is hook-managed. */
    class LinkedService
    {
        private function gcAuthyUserSync($e, string $u, string $p): void {}
        private function gcAuthyUserProvision(string $u, string $p, string $e): int { return 1; }
    }

    $fields = ['IdTimeLine', 'IdAuthy', 'Name', 'IdCreation'];

    $writable = function (Api $api, string $col) use ($fields): bool {
        $m = new ReflectionMethod($api, 'isWritableColumn');
        $m->setAccessible(true);
        return (bool) $m->invoke($api, $col, $fields);
    };

    $fail = 0;
    $check = function (string $label, bool $cond) use (&$fail) {
        echo ($cond ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
        if (!$cond) { $fail++; }
    };

    $plain = new Api('time_line', new PlainService(), ['IdAuthy', 'Name']);
    $check('plain service: IdAuthy writable when on the form allowlist', $writable($plain, 'IdAuthy'));
    $check('plain service: other system columns stay denied', !$writable($plain, 'IdCreation'));

    $plainNoAllowlist = new Api('time_line', new PlainService());
    $check('plain service, no allowlist: IdAuthy writable', $writable($plainNoAllowlist, 'IdAuthy'));

    $linked = new Api('time_line', new LinkedService(), ['IdAuthy', 'Name']);
    $check('with_authy_user service: IdAuthy denied even when on the allowlist', !$writable($linked, 'IdAuthy'));
    $check('with_authy_user service: Name still writable', $writable($linked, 'Name'));

    $none = new Api('time_line', null, ['IdAuthy', 'Name']);
    $check('no service (fail-closed): IdAuthy denied', !$writable($none, 'IdAuthy'));

    $unknownClass = new Api('time_line', 'App\\NoSuchService', ['IdAuthy']);
    $check('unresolvable class name (fail-closed): IdAuthy denied', !$writable($unknownClass, 'IdAuthy'));

    $check('SYSTEM_COLUMNS constant still lists IdAuthy', in_array('IdAuthy', Api::SYSTEM_COLUMNS, true));

    echo PHP_EOL . ($fail ? "$fail FAILED" : 'all passed') . PHP_EOL;
    exit($fail ? 1 : 0);
}
