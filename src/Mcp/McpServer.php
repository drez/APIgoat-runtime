<?php
namespace ApiGoat\Mcp;

use ApiGoat\Sessions\AuthySession;

class McpServer
{
    private const PROTOCOL = '2025-06-18';

    public function __construct(private ToolRegistry $registry) {}

    /** @return array|null JSON-RPC response, or null for a notification (no id). */
    public function handle(array $message, AuthySession $session): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? '';
        $isNotification = !array_key_exists('id', $message);

        try {
            switch ($method) {
                case 'initialize':
                    return $this->ok($id, $this->initialize($message['params'] ?? []));
                case 'notifications/initialized':
                    return null; // accepted, no response
                case 'tools/list':
                    return $this->ok($id, ['tools' => $this->registry->list($session)]);
                case 'tools/call':
                    try {
                        return $this->ok($id, $this->call($message['params'] ?? [], $session));
                    } catch (\DomainException $e) {
                        return $this->err($id, (int) $e->getCode(), $e->getMessage());
                    }
                default:
                    if ($isNotification) {
                        return null;
                    }
                    return $this->err($id, -32601, "Method not found: {$method}");
            }
        } catch (\League\OAuth2\Server\Exception\OAuthServerException $e) {
            // Auth errors must propagate to McpEndpoint for a 401, not be
            // swallowed into a -32603 JSON-RPC internal error.
            throw $e;
        } catch (\Throwable $e) {
            return $this->err($id, -32603, 'Internal error');
        }
    }

    /** Generic guidance when the project manifest sets no 'instructions'. */
    private const DEFAULT_INSTRUCTIONS =
        'Start with crm_describe (no arguments) to learn which entities you may access; '
        . 'pass an entity name for its fields before writing. Search with crm_list — filter: '
        . '{"Entity": [["col", value, "ne|lt|gt|or"?]]}, "%" in a value means LIKE; order: '
        . '[["col","asc|desc"]]. Read one record with crm_get, write with crm_create/crm_update. '
        . 'crm_create validates required fields (a rejected create lists the missing ones — ask '
        . 'the user for values, never invent them) and executes only with confirm:true: show the '
        . 'user the pending record and get their approval first. crm_delete is gated the same way '
        . '— never delete without the user\'s explicit approval of that specific delete. '
        . 'To page through a long list pass page:N (with limit as the page size): the answer becomes '
        . '{rows, page, per_page, total, last_page}. '
        . 'Prefer this server\'s custom (non-crm_) tools whenever one matches the task.';

    private function initialize(array $params): array
    {
        // Build-time tool-list stamp (config/Built/mcp.version.php): drives the
        // reported version and prefixes the instructions with a what's-new notice
        // so connectors already installed on a client learn about new tools —
        // the stateless POST transport cannot push tools/list_changed.
        $stamp        = VersionStamp::read();
        $instructions = $this->registry->instructions() ?? self::DEFAULT_INSTRUCTIONS;
        $whatsNew     = VersionStamp::whatsNew($stamp);
        if ($whatsNew !== null) {
            $instructions = $whatsNew . "\n\n" . $instructions;
        }
        return [
            // TODO: negotiate against $params['protocolVersion'] when we support multiple versions
            'protocolVersion' => self::PROTOCOL,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => self::serverInfo(
                $this->registry->manifestValue('name'),
                $this->registry->manifestValue('title'),
                $stamp['version'] ?? $this->registry->manifestValue('version')
            ),
            'instructions' => $instructions,
        ];
    }

    /**
     * Per-project server identity. Every GoatCheese project runs this same
     * runtime, so the name MUST come from the project, not a constant here —
     * connectors listed side by side (apigtbot, apichatbot, apicrm, …) are
     * otherwise indistinguishable. Precedence: config/mcp.php 'name' /
     * 'title' → GC_MCP_NAME (.env; deploy pins it so every checkout that
     * deploys to the same host serves the same identity) → _PROJECT_NAME
     * (config/Built/config.php, i.e. the LOCAL checkout's folder name) →
     * 'apigoat'.
     * The name is slugged to [A-Za-z0-9_.-] (MCP clients use it as an
     * identifier); the title is free text shown to humans.
     *
     * The version is the build-time tool-list stamp (VersionStamp) when one
     * exists, else config/mcp.php 'version', else '1'.
     *
     * @return array{name:string,title:string,version:string}
     */
    public static function serverInfo($manifestName = null, $manifestTitle = null, $version = null): array
    {
        $project = defined('_PROJECT_NAME') && trim((string) _PROJECT_NAME) !== '' ? trim((string) _PROJECT_NAME) : 'apigoat';
        $envName = self::envMcpName();
        if ($envName !== '') {
            $project = $envName;
        }
        $name = is_string($manifestName) && trim($manifestName) !== '' ? trim($manifestName)
              : ($envName !== '' ? $envName : $project . '-mcp');
        $name = trim(preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name), '-') ?: 'apigoat-mcp';
        $title = is_string($manifestTitle) && trim($manifestTitle) !== '' ? trim($manifestTitle) : $project . ' MCP';
        $version = is_scalar($version) && trim((string) $version) !== '' ? trim((string) $version) : '1';
        return ['name' => $name, 'title' => mb_substr($title, 0, 100), 'version' => $version];
    }

    /** GC_MCP_NAME from the loaded .env (env() helper, $_ENV, then getenv); '' when unset. */
    public static function envMcpName(): string
    {
        $v = null;
        if (\function_exists('env')) {
            $v = env('GC_MCP_NAME');
        }
        if (!\is_string($v) || trim($v) === '') {
            $v = $_ENV['GC_MCP_NAME'] ?? null;
        }
        if (!\is_string($v) || trim($v) === '') {
            $v = \getenv('GC_MCP_NAME');
        }
        return \is_string($v) ? trim($v) : '';
    }

    private function call(array $params, AuthySession $session): array
    {
        $name = $params['name'] ?? '';
        $tool = $this->registry->get($name);
        if ($tool === null) {
            throw new \DomainException("Unknown tool '{$name}'", -32602);
        }
        try {
            return $tool->handle((array) ($params['arguments'] ?? []), $session);
        } catch (ToolError $te) {
            $msgs = $te->messages;
            array_unshift($msgs, $te->getMessage());
            return ['content' => [['type' => 'text', 'text' => implode('; ', $msgs)]], 'isError' => true];
        }
    }

    private function ok($id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function err($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
