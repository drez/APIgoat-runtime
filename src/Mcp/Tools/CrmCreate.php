<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Sessions\AuthySession;

class CrmCreate extends AbstractCrmTool
{
    public function name(): string { return 'crm_create'; }
    public function description(): string { return 'Create a new CRM row. data = writable column → value (see crm_describe).'; }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['entity', 'data'], 'properties' => [
            'entity' => ['type' => 'string'],
            'data' => ['type' => 'object', 'description' => 'column → value; only writable columns (see crm_describe)'],
        ]];
    }

    public function handle(array $args, AuthySession $session): array
    {
        $entity = (string) ($args['entity'] ?? '');
        $catalog = $this->catalog($session);
        $this->assertEntityPermitted($catalog, $entity, 'create');
        $this->assertWritable($catalog, $entity, (array) ($args['data'] ?? []));
        return self::mapEnvelope($this->dispatch($entity, $this->buildRequest($args)));
    }

    protected function buildRequest(array $args): array
    {
        return array_merge(self::baseRequest((string) $args['entity'], 'POST'), [
            'action' => 'create',
            'data' => (array) ($args['data'] ?? []),
        ]);
    }
}
