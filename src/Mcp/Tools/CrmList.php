<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Sessions\AuthySession;

class CrmList extends AbstractCrmTool
{
    public function name(): string { return 'crm_list'; }
    public function description(): string { return 'List/search rows of a CRM entity with filter/order/select/pagination. add_i18n columns are included per row — pass lang to read a specific locale.'; }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['entity'], 'properties' => [
            'entity' => ['type' => 'string'],
            'lang' => ['type' => 'string', 'description' => 'Locale for add_i18n columns (e.g. fr_CA); default: each record\'s own language'],
            'filter' => ['type' => 'object', 'description' => '{ "<Entity>": [ ["col", value, "ne|lt|gt|or"?], … ] }; omit op = equals; "%" = LIKE'],
            'order' => ['type' => 'array', 'items' => ['type' => 'array'], 'description' => '[ ["col","asc|desc"], … ]'],
            'select' => ['type' => 'array', 'items' => ['type' => 'string']],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30],
            'page' => ['type' => 'integer', 'minimum' => 1],
            'max_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'query' => ['type' => 'object', 'description' => 'advanced raw QueryBuilder query (join/groupby); overrides structured fields'],
        ]];
    }

    public function handle(array $args, AuthySession $session): array
    {
        $entity = (string) ($args['entity'] ?? '');
        $this->assertEntityPermitted($this->catalog($session), $entity, 'read');
        $lang = $this->assertValidLang($args);
        $env  = $this->dispatch($entity, $this->buildRequest($args));
        if (($env['status'] ?? '') === 'success' && isset($env['data'])) {
            $env['data'] = $this->mergeI18nColumnsIntoRows($entity, $env['data'], $lang, $this->userLocale($session));
        }
        return self::mapEnvelope($env);
    }

    protected function buildRequest(array $args): array
    {
        $entity = (string) $args['entity'];
        // raw query escape hatch overrides the structured fields
        if (isset($args['query']) && is_array($args['query'])) {
            $query = $args['query'];
        } else {
            $query = [];
            foreach (['filter', 'order', 'select', 'limit', 'page', 'max_page'] as $k) {
                if (isset($args[$k])) {
                    $query[$k] = $args[$k];
                }
            }
        }
        return array_merge(self::baseRequest($entity, 'POST'), [
            'a' => 'list',
            'data' => ['query' => $query],
            'normalized_query' => $query,   // QueryBuilder reads this directly in-process
        ]);
    }
}
