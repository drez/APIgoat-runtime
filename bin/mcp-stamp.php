<?php
/**
 * gc build helper: stamp the project's MCP tool-list version.
 *
 *   php vendor/apigoat/runtime/bin/mcp-stamp.php [/path/to/.admin]
 *
 * Boots the project (autoload + config/Built/config.php), discovers the tools
 * exactly like the live server (built-ins + src/App/Mcp/Tools + manifest,
 * minus 'disabled'), and writes config/Built/mcp.version.php through
 * ApiGoat\Mcp\VersionStamp. Prints a one-line JSON summary.
 */
$admin = rtrim($argv[1] ?? getcwd(), '/\\');
if (!is_file($admin . '/vendor/autoload.php') || !is_file($admin . '/config/Built/config.php')) {
    fwrite(STDERR, "mcp-stamp: not a built project dir: {$admin}\n");
    exit(2);
}
require $admin . '/vendor/autoload.php';
require $admin . '/config/Built/config.php';

if (!is_file($admin . '/config/mcp.php')) {
    echo json_encode(['skipped' => 'no config/mcp.php']), "\n";
    exit(0);
}
$registry = new \ApiGoat\Mcp\ToolRegistry();
$names = [];
foreach ($registry->all() as $name => $tool) {
    if ($registry->get($name) !== null) { // drops 'disabled' ones, like the live list
        $names[] = $name;
    }
}
$res = \ApiGoat\Mcp\VersionStamp::write($admin, $names);
echo json_encode([
    'version' => $res['stamp']['version'],
    'tools'   => count($res['stamp']['tools']),
    'changed' => $res['changed'],
    'added'   => $res['stamp']['added'],
    'removed' => $res['stamp']['removed'],
], JSON_UNESCAPED_SLASHES), "\n";
