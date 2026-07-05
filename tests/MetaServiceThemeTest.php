<?php
// Run: php vendor/apigoat/runtime/tests/MetaServiceThemeTest.php

// Stub the interfaces and classes Service depends on so MetaService can be
// loaded without a full Slim/PSR environment — matching OAuthMiddlewarePassthroughTest.php.
namespace Psr\Http\Message {
    interface ResponseInterface {}
    interface ServerRequestInterface {}
}
namespace ApiGoat\Utility {
    class BuilderLayout { public function __construct($m) {} }
    class BuilderMenus  { public function __construct($a) {} }
}
namespace ApiGoat\Api {
    class ApiResponse  { public function __construct($a, $r, $b) {} }
    class MetaCatalog  {}
}
namespace ApiGoat\Services {
    class MetaFilter   { public static function filterScreens($s, $sess): array { return []; } }
}
namespace {

require __DIR__ . '/../src/Services/Service.php';
require __DIR__ . '/../src/Services/MetaService.php';

use ApiGoat\Services\MetaService;
function t(bool $c, string $m): void { if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } }

// A minimal session stub exposing sessVar + get().
$mk = function ($theme) {
  return new class($theme) {
    public array $sessVar; private $id = 7;
    public function __construct($theme) { $this->sessVar = $theme === null ? [] : ['Theme' => $theme]; }
    public function get($k) { return $k === 'id' ? $this->id : null; }
  };
};
$allowed = ['mint', 'ink', 'indigo', 'terracotta', 'graphite'];
t(MetaService::resolveTheme($mk('ink'), $allowed) === 'ink', 'valid cached theme returned');
t(MetaService::resolveTheme($mk('bogus'), $allowed) === 'mint', 'invalid theme -> mint');
t(MetaService::resolveTheme($mk(null), $allowed) === 'mint', 'no theme -> mint');
t(MetaService::resolveTheme(null, $allowed) === 'mint', 'no session -> mint');
echo "PASS: MetaService theme OK\n"; exit(0);

} // namespace {
