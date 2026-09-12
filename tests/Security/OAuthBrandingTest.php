<?php
// Run: php tests/Security/OAuthBrandingTest.php
//
// Branding + view-fallback guard for the OAuth login/consent pages.
//
// The pages are project-branded in two layers: a template-managed view
// (public/view/oauth-login.php / oauth-consent.php, drift-synced by gc) and
// an inline fallback inside OAuthAuthorizeService for projects whose
// template never synced. Invariants:
//
//   1. Branding::productName() ladder: `branding_product_name` config row ->
//      _SITE_TITLE -> ucfirst(_PROJECT_NAME) -> 'App'. (The DB rung is
//      exercised implicitly: \App\ConfigQuery resolves here but Propel is
//      not configured, so AiConfig must swallow the failure and fall
//      through — a crash on that rung fails the first check.)
//   2. logoUrl()/faviconUrl() return '' rather than a URL to a missing file
//      (a broken <img> on a kid's sign-in was the old admin-login bug).
//   3. A missing view falls back to the inline page; a present view is
//      used; a THROWING view falls back instead of 500-ing mid-OAuth-flow.
//   4. The client name (attacker-controlled via RFC 7591 dynamic
//      registration client_name) is escaped in BOTH the fallback and the
//      view path — extends the HtmlEscapingTest guarantee to this surface.
//
// Constants define once per process, so the ladder checks run weakest-first
// (no constants -> _PROJECT_NAME -> _SITE_TITLE): each define() shadows the
// previous rung exactly like a real project config would.

(function () {
    // Prefer THIS checkout: deps from its own vendor/, ApiGoat\ classes via a
    // PSR-4 shim — the package declares no composer autoload section (each
    // project maps ApiGoat\ itself), and a project's vendored runtime may be
    // OLDER than the code under test, so project autoloads come last. Do not
    // stack several project autoloads: two projects can share a composer
    // autoloader class hash and fatal on redeclare.
    $own = __DIR__ . '/../../vendor/autoload.php';
    if (is_file($own)) {
        require $own;
        spl_autoload_register(function ($class) {
            if (strncmp($class, 'ApiGoat\\', 8) === 0) {
                $file = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });
        if (class_exists(\ApiGoat\Services\OAuthAuthorizeService::class)
            && class_exists(\ApiGoat\Utility\Branding::class)) {
            return;
        }
    }
    foreach ([getcwd() . '/vendor/autoload.php', getcwd() . '/.admin/vendor/autoload.php'] as $autoload) {
        if (is_file($autoload)) {
            require $autoload;
            if (class_exists(\ApiGoat\Services\OAuthAuthorizeService::class)
                && class_exists(\ApiGoat\Utility\Branding::class)) {
                return;
            }
            break; // one project autoload only; its runtime is just too old
        }
    }
    fwrite(STDERR, "Cannot locate an autoloader that resolves OAuthAuthorizeService + Branding.\n");
    exit(2);
})();

use ApiGoat\Services\OAuthAuthorizeService;
use ApiGoat\Utility\Branding;

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
function has(string $haystack, string $needle): bool
{
    return strpos($haystack, $needle) !== false;
}

// ---- productName ladder (weakest rung first; each define shadows the last)

check('no config row, no constants -> App', Branding::productName(), 'App');

define('_PROJECT_NAME', 'apigtutor');
check('_PROJECT_NAME rung is ucfirst()ed', Branding::productName(), 'Apigtutor');

define('_SITE_TITLE', 'Lumi Tutor');
check('_SITE_TITLE outranks _PROJECT_NAME', Branding::productName(), 'Lumi Tutor');

// ---- logo / favicon: '' rather than a URL to a missing file

$tmp = sys_get_temp_dir() . '/oauth-branding-test-' . getmypid();
mkdir($tmp . '/public/img', 0777, true);
mkdir($tmp . '/view', 0777, true);

check('logoUrl without _INSTALL_PATH -> empty', Branding::logoUrl(), '');
check('faviconUrl without _INSTALL_PATH -> empty', Branding::faviconUrl(), '');

define('_INSTALL_PATH', $tmp . '/');
define('_SITE_URL', 'https://example.test/app/');

check('logoUrl with no logo file -> empty (no broken <img>)', Branding::logoUrl(), '');
check('faviconUrl with no favicon file -> empty', Branding::faviconUrl(), '');

file_put_contents($tmp . '/public/img/logo-admin.png', 'png');
file_put_contents($tmp . '/public/img/fav-2.1.png', 'png');
check('default logo resolves under _SITE_URL', Branding::logoUrl(), 'https://example.test/app/public/img/logo-admin.png');
check('favicon resolves under _SITE_URL', Branding::faviconUrl(), 'https://example.test/app/public/img/fav-2.1.png');

define('LOGO_URL_LOGIN', 'custom-login.png');
check('defined LOGO_URL_LOGIN with missing file falls back to default', Branding::logoUrl(), 'https://example.test/app/public/img/logo-admin.png');
file_put_contents($tmp . '/public/img/custom-login.png', 'png');
check('LOGO_URL_LOGIN wins once its file exists (same rule as admin login)', Branding::logoUrl(), 'custom-login.png');

// ---- fallback page: branded copy, no "CRM", client name escaped

$vars = [
    'productName'      => 'Lumi Tutor',
    'logoUrl'          => Branding::logoUrl(),
    'faviconUrl'       => Branding::faviconUrl(),
    'clientName'       => '"><script>alert(1)</script>',
    'errorHtml'        => '',
    'actionUrl'        => 'https://example.test/app/oauth/authorize?a=1&b=2',
    'hiddenFieldsHtml' => '<input type="hidden" name="csrf" value="tok">',
];

$emptyDir = $tmp . '/view'; // exists, holds no views -> inline fallback
$login    = OAuthAuthorizeService::loginPageHtml($vars, $emptyDir);

check('missing view -> inline login fallback renders', has($login, 'Sign in to Lumi Tutor'), true);
check('fallback login says the PRODUCT, not "CRM account"', has($login, 'CRM account'), false);
check('fallback login: raw client_name never reaches the page', has($login, '<script>alert(1)'), false);
check('fallback login: client_name is escaped, not dropped', has($login, '&lt;script&gt;'), true);
check('fallback login: action URL is attribute-escaped', has($login, 'a=1&amp;b=2'), true);
check('fallback login: hidden CSRF field passes through verbatim', has($login, 'name="csrf" value="tok"'), true);

$consent = OAuthAuthorizeService::consentPageHtml($vars + [
    'whoHtml'        => '<p>Signed in as Kid</p>',
    'scopeItemsHtml' => '<li>See your account and your progress</li>',
], $emptyDir);

check('fallback consent names the product', has($consent, 'your Lumi Tutor account'), true);
check('fallback consent: client_name escaped', has($consent, '<script>alert(1)'), false);
check('fallback consent: scope list passes through verbatim', has($consent, 'See your account and your progress'), true);
check('fallback consent keeps Allow/Deny + switch-account', has($consent, 'name="switch_account"'), true);

// ---- a present view is used, with the SAME pre-escaped vars

file_put_contents(
    $tmp . '/view/oauth-login.php',
    '<?php echo "VIEW-MARKER product=[$productName] client=[$clientName]"; ?>'
);
$viaView = OAuthAuthorizeService::loginPageHtml($vars, $tmp . '/view');
check('present view is rendered instead of the fallback', has($viaView, 'VIEW-MARKER'), true);
check('view receives the escaped client name', has($viaView, '&lt;script&gt;'), true);
check('view path: raw client_name never reaches the page', has($viaView, '<script>alert(1)'), false);

// ---- a THROWING view falls back instead of 500-ing

file_put_contents(
    $tmp . '/view/oauth-consent.php',
    '<?php echo "half-rendered garbage"; throw new RuntimeException("boom");'
);
$afterThrow = OAuthAuthorizeService::consentPageHtml($vars + [
    'whoHtml'        => '',
    'scopeItemsHtml' => '<li>x</li>',
], $tmp . '/view');
check('throwing view falls back to the inline page', has($afterThrow, 'your Lumi Tutor account'), true);
check('throwing view leaks no partial output', has($afterThrow, 'half-rendered garbage'), false);

// ---- the real template-canonical views, when this checkout has them

$templateViews = __DIR__ . '/../../../template/.admin/public/view';
if (is_file($templateViews . '/oauth-login.php') && is_file($templateViews . '/oauth-consent.php')) {
    $tplLogin = OAuthAuthorizeService::loginPageHtml($vars, $templateViews);
    check('template login view: branded heading', has($tplLogin, 'Sign in to Lumi Tutor'), true);
    check('template login view: client_name escaped', has($tplLogin, '<script>alert(1)'), false);
    check('template login view: no "CRM account"', has($tplLogin, 'CRM account'), false);
    check('template login view: favicon + logo emitted when resolvable', has($tplLogin, 'fav-2.1.png') && has($tplLogin, 'custom-login.png'), true);

    $tplConsent = OAuthAuthorizeService::consentPageHtml($vars + [
        'whoHtml'        => '<p class="gc-oauth-who">Signed in as Kid</p>',
        'scopeItemsHtml' => '<li>See your account and your progress</li>',
    ], $templateViews);
    check('template consent view: names the product', has($tplConsent, 'your Lumi Tutor account'), true);
    check('template consent view: client_name escaped', has($tplConsent, '<script>alert(1)'), false);
    check('template consent view: both forms carry the hidden fields', substr_count($tplConsent, 'name="csrf" value="tok"') === 2, true);
} else {
    echo "SKIP  template-canonical views not present in this checkout\n";
}

// cleanup
foreach (glob($tmp . '/public/img/*') as $f) { unlink($f); }
foreach (glob($tmp . '/view/*') as $f) { unlink($f); }
rmdir($tmp . '/public/img'); rmdir($tmp . '/public'); rmdir($tmp . '/view'); rmdir($tmp);

echo $fail === 0 ? "ALL PASS\n" : "FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
