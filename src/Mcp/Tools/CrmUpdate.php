<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Sessions\AuthySession;

class CrmUpdate extends AbstractCrmTool
{
    public function name(): string { return 'crm_update'; }
    public function description(): string { return 'Update an existing CRM row by id. data = writable column → value.'; }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['entity', 'id', 'data'], 'properties' => [
            'entity' => ['type' => 'string'], 'id' => ['type' => ['integer', 'string']],
            'data' => ['type' => 'object'],
        ]];
    }

    public function handle(array $args, AuthySession $session): array
    {
        $entity = (string) ($args['entity'] ?? '');
        $catalog = $this->catalog($session);
        $this->assertEntityPermitted($catalog, $entity, 'update');
        $this->assertWritable($catalog, $entity, (array) ($args['data'] ?? []));
        return self::mapEnvelope($this->dispatch($entity, $this->buildRequest($args)));
    }

    protected function buildRequest(array $args): array
    {
        return array_merge(self::baseRequest((string) $args['entity'], 'PATCH'), [
            'i' => $args['id'] ?? '',
            'action' => 'update',
            'data' => (array) ($args['data'] ?? []),
        ]);
    }
}
