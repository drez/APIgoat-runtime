<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\ToolError;
use ApiGoat\Sessions\AuthySession;

class CrmDelete extends AbstractCrmTool
{
    public function name(): string { return 'crm_delete'; }
    public function description(): string { return 'Delete a CRM row by id. Pass confirm:true to execute.'; }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['entity', 'id'], 'properties' => [
            'entity' => ['type' => 'string'], 'id' => ['type' => ['integer', 'string']],
            'confirm' => ['type' => 'boolean', 'description' => 'must be true to execute'],
        ]];
    }

    public function handle(array $args, AuthySession $session): array
    {
        if (empty($args['confirm'])) {
            throw new ToolError('Set confirm:true to delete.', [], 'bad_request');
        }
        $entity = (string) ($args['entity'] ?? '');
        $this->assertEntityPermitted($this->catalog($session), $entity, 'delete');
        return self::mapEnvelope($this->dispatch($entity, $this->buildRequest($args)));
    }

    protected function buildRequest(array $args): array
    {
        return array_merge(self::baseRequest((string) $args['entity'], 'DELETE'), ['i' => $args['id'] ?? '', 'data' => []]);
    }
}
