<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\ToolError;
use ApiGoat\Sessions\AuthySession;

class CrmCreate extends AbstractCrmTool
{
    public function name(): string { return 'crm_create'; }
    public function description(): string { return 'Create a new CRM row. data = writable column → value (see crm_describe); all required fields must be provided — ask the user for missing values, never invent them. A call without confirm:true creates nothing: it validates and echoes the pending record — show it to the user, then re-call with confirm:true once they approve.'; }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['entity', 'data'], 'properties' => [
            'entity' => ['type' => 'string'],
            'data' => ['type' => 'object', 'description' => 'column → value; only writable columns (see crm_describe)'],
            'confirm' => ['type' => 'boolean', 'description' => 'must be true to execute; get the user\'s approval of the pending record first'],
            'lang' => ['type' => 'string', 'description' => 'Scope add_i18n column writes to this locale only (e.g. fr_CA); omit to write every language'],
        ]];
    }

    public function handle(array $args, AuthySession $session): array
    {
        $entity = (string) ($args['entity'] ?? '');
        $data = (array) ($args['data'] ?? []);
        $catalog = $this->catalog($session);
        $this->assertEntityPermitted($catalog, $entity, 'create');
        $this->assertWritable($catalog, $entity, $data);
        $this->assertRequired($catalog, $entity, $data);
        $this->assertValidLang($args);
        if (empty($args['confirm'])) {
            throw new ToolError(
                "Nothing created yet — show the user this pending {$entity} and re-call with confirm:true once they approve: "
                . json_encode($data, JSON_UNESCAPED_SLASHES),
                [],
                'bad_request'
            );
        }
        return self::mapEnvelope($this->dispatch($entity, $this->buildRequest($args)));
    }

    protected function buildRequest(array $args): array
    {
        return array_merge(self::baseRequest((string) $args['entity'], 'POST'), [
            'action' => 'create',
            'data' => (array) ($args['data'] ?? []),
            'lang' => trim((string) ($args['lang'] ?? '')),
        ]);
    }
}
