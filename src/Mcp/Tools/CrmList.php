<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Sessions\AuthySession;

class CrmList extends AbstractCrmTool
{
    public function name(): string { return 'crm_list'; }
    public function description(): string { return 'List/search rows of a CRM entity with filter/order/select/pagination. Without page: a bare row array (at most limit rows). With page: {rows, page, per_page, total, last_page} — keep calling with page+1 until page == last_page; a primary-key tiebreaker keeps the sequence stable. add_i18n columns are included per row — pass lang to read a specific locale.'; }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['entity'], 'properties' => [
            'entity' => ['type' => 'string'],
            'lang' => ['type' => 'string', 'description' => 'Locale for add_i18n columns (e.g. fr_CA); default: each record\'s own language'],
            'filter' => ['type' => 'object', 'description' => '{ "<Entity>": [ ["col", value, "ne|lt|gt|or"?], … ] }; omit op = equals; "%" = LIKE'],
            'order' => ['type' => 'array', 'items' => ['type' => 'array'], 'description' => '[ ["col","asc|desc"], … ]'],
            'select' => ['type' => 'array', 'items' => ['type' => 'string']],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30, 'description' => 'Rows per call; also the page size when page is set'],
            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => '1-based page; the result becomes {rows, page, per_page, total, last_page}'],
            'max_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Deprecated alias of limit (page size when paging)'],
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
        return self::mapEnvelope(self::shapePaged($env));
    }

    /**
     * A paged list (Api::getJson set `page`) answers {rows, page, per_page,
     * total, last_page}; an unpaged one stays a bare row array. Pure.
     */
    public static function shapePaged(array $env): array
    {
        if (($env['status'] ?? '') === 'success' && isset($env['page']) && is_array($env['page']) && is_array($env['data'] ?? null)) {
            $env['data'] = ['rows' => $env['data']] + $env['page'];
        }
        return $env;
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
            // The JSON schema's 1..100 bounds are advisory to the client; enforce them.
            foreach (['limit', 'max_page', 'page'] as $k) {
                if (isset($query[$k])) {
                    $query[$k] = max(1, $k === 'page' ? (int) $query[$k] : min(100, (int) $query[$k]));
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
