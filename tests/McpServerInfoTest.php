<?php
// Run: php vendor/apigoat/runtime/tests/McpServerInfoTest.php
// Every GoatCheese project shares this runtime, so the MCP serverInfo must be
// derived from the project (or its config/mcp.php manifest), never hardcoded —
// otherwise every connector lists as the same server.
require __DIR__ . '/../src/Mcp/McpTool.php';
require __DIR__ . '/../src/Mcp/ToolError.php';
if (!class_exists('ApiGoat\Sessions\AuthySession')) { eval('namespace ApiGoat\Sessions; class AuthySession {}'); }
if (!class_exists('ApiGoat\Mcp\ToolRegistry')) { eval('namespace ApiGoat\Mcp; class ToolRegistry { public function manifestValue(string $k) { return null; } public function instructions() { return null; } }'); }
require __DIR__ . '/../src/Mcp/McpServer.php';

use ApiGoat\Mcp\McpServer;

function assertEq($a, $b, string $m): void { if ($a !== $b) { fwrite(STDERR, "FAIL: $m (" . json_encode($a) . " !== " . json_encode($b) . ")\n"); exit(1); } }

// No project constant, no manifest → generic fallback.
$i = McpServer::serverInfo();
assertEq($i['name'], 'apigoat-mcp', 'fallback name');
assertEq($i['title'], 'apigoat MCP', 'fallback title');
assertEq($i['version'], '1', 'version kept');

// Project constant drives the default.
define('_PROJECT_NAME', 'apichatbot');
$i = McpServer::serverInfo();
assertEq($i['name'], 'apichatbot-mcp', 'project name → <project>-mcp');
assertEq($i['title'], 'apichatbot MCP', 'project title');

// Manifest overrides win; name is slugged, title is free text.
$i = McpServer::serverInfo('APIchatbot (iapo) prod!', 'APIchatbot — iapo');
assertEq($i['name'], 'APIchatbot-iapo-prod', 'manifest name slugged to identifier chars');
assertEq($i['title'], 'APIchatbot — iapo', 'manifest title verbatim');

// Blank / non-string manifest values fall back.
$i = McpServer::serverInfo('   ', ['not' => 'a string']);
assertEq($i['name'], 'apichatbot-mcp', 'blank manifest name ignored');
assertEq($i['title'], 'apichatbot MCP', 'non-string manifest title ignored');

echo "OK McpServerInfoTest\n";
