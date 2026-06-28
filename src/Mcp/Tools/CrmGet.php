<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Sessions\AuthySession;

class CrmGet extends AbstractCrmTool
{
    public function name(): string { return 'crm_get'; }
    public function description(): string { return 'Fetch a single CRM row by id.'; }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['entity', 'id'], 'properties' => [
            'entity' => ['type' => 'string'], 'id' => ['type' => ['integer', 'string']],
        ]];
    }

    public function handle(array $args, AuthySession $session): array
    {
        $entity = (string) ($args['entity'] ?? '');
        $this->assertEntityPermitted($this->catalog($session), $entity, 'read');
        return self::mapEnvelope($this->dispatch($entity, $this->buildRequest($args)));
    }

    protected function buildRequest(array $args): array
    {
        return array_merge(self::baseRequest((string) $args['entity'], 'GET'), [
            'i' => $args['id'] ?? '',
            'data' => [], 'normalized_query' => [],
        ]);
    }
}
