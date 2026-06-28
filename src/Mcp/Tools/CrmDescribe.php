<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\ToolError;
use ApiGoat\Sessions\AuthySession;

class CrmDescribe extends AbstractCrmTool
{
    public function name(): string { return 'crm_describe'; }

    public function description(): string
    {
        return 'Describe the CRM entities you may access and their fields (name, type, required, writable, enum, relations). Omit "entity" to list all entities; pass "entity" for one entity\'s fields.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'entity' => ['type' => 'string', 'description' => "Optional model name (e.g. 'Contact'). Omit to list every entity you may access."],
        ]];
    }

    public function handle(array $args, AuthySession $session): array
    {
        $catalog = $this->catalog($session);
        $entity  = $args['entity'] ?? null;

        if ($entity === null || $entity === '') {
            $list = [];
            foreach ($catalog['entities'] ?? [] as $name => $def) {
                $list[$name] = ['label' => $def['label'] ?? $name, 'permissions' => $def['permissions'] ?? []];
            }
            return [
                'content' => [['type' => 'text', 'text' => json_encode(['entities' => $list], JSON_UNESCAPED_SLASHES)]],
                'isError' => false,
            ];
        }

        $this->assertEntityPermitted($catalog, (string) $entity, ''); // existence/read gate

        return [
            'content' => [['type' => 'text', 'text' => json_encode($catalog['entities'][$entity], JSON_UNESCAPED_SLASHES)]],
            'isError' => false,
        ];
    }
}
