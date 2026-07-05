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
        . 'Prefer this server\'s custom (non-crm_) tools whenever one matches the task.';

    private function initialize(array $params): array
    {
        return [
            // TODO: negotiate against $params['protocolVersion'] when we support multiple versions
            'protocolVersion' => self::PROTOCOL,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'apicrm-mcp', 'version' => '1'],
            'instructions' => $this->registry->instructions() ?? self::DEFAULT_INSTRUCTIONS,
        ];
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
