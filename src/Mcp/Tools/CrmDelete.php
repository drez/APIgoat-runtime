<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\ToolError;
use ApiGoat\Sessions\AuthySession;

class CrmDelete extends AbstractCrmTool
{
    public function name(): string { return 'crm_delete'; }
    public function description(): string { return 'Delete a CRM row by id. Destructive — ALWAYS requires the user\'s explicit approval. A call without confirm:true deletes nothing: show the user which record will be deleted and re-call with confirm:true only after they approve this specific delete. Never set confirm:true on your own.'; }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['entity', 'id'], 'properties' => [
            'entity' => ['type' => 'string'], 'id' => ['type' => ['integer', 'string']],
            'confirm' => ['type' => 'boolean', 'description' => 'must be true to execute; set only after the user explicitly approves this specific delete'],
        ]];
    }

    public function handle(array $args, AuthySession $session): array
    {
        if (empty($args['confirm'])) {
            throw new ToolError(
                'Nothing deleted yet — deleting ' . (string) ($args['entity'] ?? '') . ' id '
                . (string) ($args['id'] ?? '') . ' requires the user\'s explicit approval. '
                . 'Show the user which record will be deleted and re-call with confirm:true '
                . 'only after they approve.',
                [],
                'bad_request'
            );
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
