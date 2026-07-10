<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Sessions\AuthySession;

class CrmGet extends AbstractCrmTool
{
    public function name(): string { return 'crm_get'; }
    public function description(): string { return 'Fetch a single CRM row by id. add_i18n columns (terms, notes, …) are included, read at the record\'s language — pass lang to read another locale.'; }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['entity', 'id'], 'properties' => [
            'entity' => ['type' => 'string'], 'id' => ['type' => ['integer', 'string']],
            'lang' => ['type' => 'string', 'description' => 'Locale for add_i18n columns (e.g. fr_CA); default: the record\'s own language'],
        ]];
    }

    public function handle(array $args, AuthySession $session): array
    {
        $entity = (string) ($args['entity'] ?? '');
        $this->assertEntityPermitted($this->catalog($session), $entity, 'read');
        $lang = $this->assertValidLang($args);
        $env  = $this->dispatch($entity, $this->buildRequest($args));
        if (($env['status'] ?? '') === 'success' && isset($env['data'])) {
            $env['data'] = $this->mergeI18nColumns($entity, $args['id'] ?? '', $env['data'], $lang);
        }
        return self::mapEnvelope($env);
    }

    protected function buildRequest(array $args): array
    {
        return array_merge(self::baseRequest((string) $args['entity'], 'GET'), [
            'i' => $args['id'] ?? '',
            'data' => [], 'normalized_query' => [],
        ]);
    }
}
