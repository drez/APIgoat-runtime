<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\McpTool;
use ApiGoat\Mcp\ToolError;
use ApiGoat\Sessions\AuthySession;

abstract class AbstractCrmTool implements McpTool
{
    /** CRUD tools take the entity as an argument; no fixed tool-level right. */
    public function requiredRight(): ?array
    {
        return null;
    }

    /**
     * Map the in-process Api envelope to an MCP tool result. Reads BOTH the
     * top-level errors[]/messages[] AND the nested data.error/data.messages
     * (ApiResponse nests Api's singular `error` + per-column data under data.*).
     * @return array{content: array, isError: bool}
     */
    public static function mapEnvelope(array $body): array
    {
        $status = $body['status'] ?? 'failure';
        $data = $body['data'] ?? null;

        $errors = (array) ($body['errors'] ?? []);
        $messages = (isset($body['messages']) && $body['messages'] !== null)
            ? (array) $body['messages']
            : [];

        if (is_array($data)) {
            if (isset($data['error']) && is_string($data['error'])) {
                $errors[] = $data['error'];
            }
            if (isset($data['messages'])) {
                $messages = array_merge($messages, (array) $data['messages']);
            }
            // per-column validation messages (col => msg), excluding reserved keys
            if (($data['error'] ?? '') === 'Validation error') {
                foreach ($data as $k => $v) {
                    if (is_string($k) && is_string($v) && !in_array($k, ['error', 'ids', 'count', 'deleted'], true)) {
                        $messages[] = "{$k}: {$v}";
                    }
                }
            }
        }

        $errors = array_values(array_unique(array_filter($errors, 'is_string')));
        $messages = array_values(array_unique(array_filter($messages, 'is_string')));

        $isError = !in_array($status, ['success', 'data'], true);

        if ($isError) {
            // For partial deletes, surface which ids were successfully removed so
            // the batch caller can see partial progress.
            if ($status === 'mixed' && is_array($data) && isset($data['deleted'])) {
                $messages[] = 'Deleted: ' . implode(',', (array) $data['deleted']);
            }
            $text = self::failureText($errors, $messages);
            return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
        }

        return [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES)]],
            'isError' => false,
        ];
    }

    private static function failureText(array $errors, array $messages): string
    {
        $all = array_merge($errors, $messages);
        $joined = implode('; ', $all);
        // friendlier RBAC phrasing
        if (stripos($joined, 'permission denied') !== false) {
            return 'Not permitted (RBAC) — your CRM role cannot perform this operation. ' . $joined;
        }
        return $joined !== '' ? $joined : 'Operation failed.';
    }

    /** Build the RBAC-filtered entity/field catalog (override in tests). */
    protected function catalog(AuthySession $session): array
    {
        $routes = require _BASE_DIR . 'config/Built/settings.routes.php';
        $entityNames = array_keys($routes['json']['GET'] ?? []);
        return (new \ApiGoat\Api\MetaCatalog($session))->build($entityNames);
    }

    /** Throw ToolError unless $entity is in the catalog and $op (read/create/update/delete) is permitted. */
    protected function assertEntityPermitted(array $catalog, string $entity, string $op): void
    {
        $entities = $catalog['entities'] ?? [];
        if (!isset($entities[$entity])) {
            throw new ToolError(
                "Unknown or not-permitted entity '{$entity}' — call crm_describe to list entities you may access.",
                [],
                'not_found'
            );
        }
        $perms = $entities[$entity]['permissions'] ?? [];
        if ($op !== '' && empty($perms[$op])) {
            throw new ToolError("Not permitted: your CRM role cannot {$op} {$entity}.", [], 'not_permitted');
        }
    }

    /**
     * Dispatch a synthetic request through the generated {Entity}Service and
     * decode the JSON envelope. Overridable in unit tests. Runs in the real
     * MCP request context (McpEndpoint passes a PSR-7 request/response down).
     */
    protected function dispatch(string $entity, array $request): array
    {
        $svc = self::resolveService($entity, $request);
        $response = $svc->getApiResponse();
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded)
            ? $decoded
            : ['status' => 'failure', 'errors' => ['Unreadable service response']];
    }

    /** Common synthetic-request fields every verb needs. RouteHelper-equivalent shape. */
    protected static function baseRequest(string $entity, string $method): array
    {
        return [
            'method' => $method,
            'p' => $entity,
            'routeName' => $entity,
            'isApiCall' => true,
            'a' => '',
            'i' => '',
            'rbac_public' => '',   // anything != 'passed' so Api::authorize() runs
            'ui' => '',
        ];
    }

    /** Reject non-writable keys and bad enum values up front (mirrors Api::isWritableColumn + crm_describe). */
    protected function assertWritable(array $catalog, string $entity, array $data): void
    {
        $fields = $catalog['entities'][$entity]['fields'] ?? [];
        $writable = [];
        foreach ($fields as $name => $def) {
            if (!empty($def['writable'])) {
                $writable[] = $name;
            }
        }
        foreach ($data as $key => $value) {
            if (!isset($fields[$key]) || empty($fields[$key]['writable'])) {
                throw new ToolError(
                    "Field '{$key}' is not writable on {$entity}.",
                    ['Writable fields: ' . implode(', ', $writable)],
                    'validation'
                );
            }
            $def = $fields[$key];
            if (($def['type'] ?? '') === 'enum' && isset($def['enum']) && is_array($def['enum'])
                && $value !== null && !in_array($value, $def['enum'], true)) {
                throw new ToolError(
                    "Invalid value for '{$key}': must be one of " . implode(', ', $def['enum']) . '.',
                    [], 'validation'
                );
            }
        }
    }

    /** \App\{Entity}ServiceWrapper → \App\{Entity}Service, both ($request,$response,$args). */
    protected static function resolveService(string $entity, array $args)
    {
        $request  = $GLOBALS['__mcp_request']  ?? null;   // set by McpEndpoint for the in-process call
        $response = $GLOBALS['__mcp_response'] ?? null;
        $wrapper  = "\\App\\{$entity}ServiceWrapper";
        $bare     = "\\App\\{$entity}Service";
        if (class_exists($wrapper)) {
            return new $wrapper($request, $response, $args);
        }
        if (class_exists($bare)) {
            return new $bare($request, $response, $args);
        }
        throw new ToolError("No service for entity '{$entity}'.", [], 'bad_request');
    }
}
