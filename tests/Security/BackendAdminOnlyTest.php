<?php
// settings `backend_admin_only` (opt-in per project): the session-authenticated
// backend is for Admin-group and root users only. Marketplace projects (vidifye)
// hand every self-registered member a session login + Owner rights meant for
// the API/app; without this gate those members could browse the admin panel.
namespace ApiGoat\Tests\Security;

use ApiGoat\Middlewares\AuthyMiddleware;
use PHPUnit\Framework\TestCase;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ApiGoat\\')) {
        $f = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
}, true, true);

final class BaoSession
{
    public function __construct(private bool $admin, private bool $root, private string $connected = 'YES') {}
    public function get($k) { return $k === 'connected' ? $this->connected : null; }
    public function isAdmin() { return $this->admin; }
    public function isRoot() { return $this->root; }
}

final class BackendAdminOnlyTest extends TestCase
{
    private function denied(BaoSession $s, array $args, bool $adminOnly = true, array $exclude = []): bool
    {
        return AuthyMiddleware::backendDenied($adminOnly, $s, $args + ['is_api' => false, 'route' => ($args['model'] ?? '') . '/' . ($args['action'] ?? '')], $exclude);
    }

    public function test_member_is_refused_the_backend(): void
    {
        $member = new BaoSession(false, false);
        $this->assertTrue($this->denied($member, ['model' => 'Product', 'action' => 'list']));
        $this->assertTrue($this->denied($member, ['model' => '', 'action' => '', 'route' => '']), 'dashboard');
        $this->assertTrue($this->denied($member, ['model' => 'Authy', 'action' => 'edit']));
        $this->assertTrue($this->denied($member, ['model' => 'Product', 'action' => 'oauth', 'route' => 'Product/oauth']), 'an "oauth" segment is not the OAuth route');
        $this->assertTrue($this->denied($member, ['model' => 'oauth', 'action' => 'x', 'route' => 'oauth/x/Product/list']), 'deep path under oauth/');
    }

    public function test_admin_and_root_keep_the_backend(): void
    {
        $this->assertFalse($this->denied(new BaoSession(true, false), ['model' => 'Product', 'action' => 'list']));
        $this->assertFalse($this->denied(new BaoSession(false, true), ['model' => 'Product', 'action' => 'list']));
    }

    public function test_off_unless_the_project_opts_in(): void
    {
        $this->assertFalse($this->denied(new BaoSession(false, false), ['model' => 'Product', 'action' => 'list'], false));
    }

    public function test_api_login_oauth_and_public_routes_stay_open(): void
    {
        $member = new BaoSession(false, false);
        $this->assertFalse($this->denied($member, ['model' => 'Product', 'action' => 'list', 'is_api' => true]), 'API is RBAC-governed, not gated');
        foreach (['login', 'auth', 'logout', 'register', 'google', 'resetConfirm'] as $a) {
            $this->assertFalse($this->denied($member, ['model' => 'Authy', 'action' => $a]), "Authy/$a");
        }
        $this->assertFalse($this->denied($member, ['model' => 'oauth', 'action' => 'authorize', 'route' => 'oauth/authorize']), 'OAuth consent');
        $this->assertFalse($this->denied($member, ['model' => 'inv', 'action' => 'x', 'route' => 'inv/tok'], true, ['inv']), 'privilege-map exclude');
    }

    public function test_anonymous_is_left_to_the_login_redirect(): void
    {
        $this->assertFalse($this->denied(new BaoSession(false, false, 'NO'), ['model' => 'Product', 'action' => 'list']));
    }
}
