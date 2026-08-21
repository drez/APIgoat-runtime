<?php
// Run: php tests/Security/OAuthPromptLoginTest.php
//
// Regression guard for kid-switching on a shared device.
//
// The mobile app (forceFreshLogin) sends OIDC `prompt=login` on every
// authorize so the next kid must type THEIR credentials. Chrome Custom Tabs
// (Android) and the web popup SHARE the CRM session cookie with the browser
// — local sign-out only drops the bearer, not that cookie. If /oauth/authorize
// ignores `prompt`, GET skips the login form and the consent POST silently
// re-issues the previous kid's code. iOS is covered separately by
// preferEphemeralSession; this is the Android/web half.
//
// shouldReauthenticate() is the decision the controller uses BEFORE looking
// at the connected flag:
//   1. no prompt                         -> keep session (show consent)
//   2. prompt=login                      -> forget session (show login)
//   3. prompt=select_account             -> same (account picker ≡ re-auth)
//   4. prompt=LOGIN (case)               -> same
//   5. prompt=login + consent=allow      -> keep session (that's the step
//                                          AFTER the fresh login)
//   6. prompt=none / consent / login_hint -> keep session

(function () {
    $candidates = [
        getcwd() . '/vendor/autoload.php',
        getcwd() . '/.admin/vendor/autoload.php', // project root, not .admin/
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../../../../autoload.php',
        '/var/www/gc/p/apigTutor/.admin/vendor/autoload.php',
    ];
    foreach (glob('/var/www/gc/p/*/.admin/vendor/autoload.php') ?: [] as $p) {
        $candidates[] = $p;
    }
    foreach ($candidates as $autoload) {
        if (is_file($autoload)) {
            require $autoload;
            if (class_exists(\ApiGoat\Services\OAuthAuthorizeService::class)) {
                return;
            }
        }
    }
    fwrite(STDERR, "Cannot locate an autoloader that resolves OAuthAuthorizeService.\n");
    exit(2);
})();

use ApiGoat\Services\OAuthAuthorizeService;

$fail = 0;
function check($label, $got, $want)
{
    global $fail;
    if ($got === $want) {
        echo "PASS  $label\n";
    } else {
        echo "FAIL  $label (got " . var_export($got, true) . ", want " . var_export($want, true) . ")\n";
        $GLOBALS['fail']++;
    }
}

check(
    'no prompt keeps the lingering session (consent)',
    OAuthAuthorizeService::shouldReauthenticate([]),
    false
);
check(
    'prompt=login forces re-auth even with a valid session cookie',
    OAuthAuthorizeService::shouldReauthenticate(['prompt' => 'login']),
    true
);
check(
    'prompt=select_account also forces re-auth',
    OAuthAuthorizeService::shouldReauthenticate(['prompt' => 'select_account']),
    true
);
check(
    'prompt is case-insensitive',
    OAuthAuthorizeService::shouldReauthenticate(['prompt' => 'LOGIN']),
    true
);
check(
    'consent POST after the fresh login keeps the NEW session',
    OAuthAuthorizeService::shouldReauthenticate(['prompt' => 'login'], 'allow'),
    false
);
check(
    'deny is also a consent decision — do not wipe the session under it',
    OAuthAuthorizeService::shouldReauthenticate(['prompt' => 'login'], 'deny'),
    false
);
check(
    'prompt=none does not force re-auth',
    OAuthAuthorizeService::shouldReauthenticate(['prompt' => 'none']),
    false
);
check(
    'prompt=consent (OIDC, already authenticated) does not force re-auth',
    OAuthAuthorizeService::shouldReauthenticate(['prompt' => 'consent']),
    false
);

check(
    'login form POST with username is a credential submission',
    OAuthAuthorizeService::isCredentialSubmission(['u' => 'fred', 'p' => 'x']),
    true
);
check(
    'whitespace-only username without password is not credentials',
    OAuthAuthorizeService::isCredentialSubmission(['u' => '  ']),
    false
);
check(
    'consent Allow POST is not a credential submission',
    OAuthAuthorizeService::isCredentialSubmission(['consent' => 'allow']),
    false
);
check(
    'switch_account=1 is the consent-page account switch',
    OAuthAuthorizeService::isSwitchAccount(['switch_account' => '1']),
    true
);
check(
    'consent=allow is not an account switch',
    OAuthAuthorizeService::isSwitchAccount(['consent' => 'allow']),
    false
);

echo $fail === 0 ? "ALL PASS\n" : "FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
