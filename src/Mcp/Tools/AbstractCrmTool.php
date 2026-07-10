<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\McpTool;
use ApiGoat\Mcp\ToolError;
use ApiGoat\Sessions\AuthySession;

abstract class AbstractCrmTool implements McpTool
{
    /** CRUD tools take the entity as an argument; no fixed tool-level right. */
    public function requiredRight(): ?array
    {
        return null;
    }

    /**
     * Map the in-process Api envelope to an MCP tool result. Reads BOTH the
     * top-level errors[]/messages[] AND the nested data.error/data.messages
     * (ApiResponse nests Api's singular `error` + per-column data under data.*).
     * @return array{content: array, isError: bool}
     */
    public static function mapEnvelope(array $body): array
    {
        $status = $body['status'] ?? 'failure';
        $data = $body['data'] ?? null;

        $errors = (array) ($body['errors'] ?? []);
        $messages = (isset($body['messages']) && $body['messages'] !== null)
            ? (array) $body['messages']
            : [];

        if (is_array($data)) {
            if (isset($data['error']) && is_string($data['error'])) {
                $errors[] = $data['error'];
            }
            if (isset($data['messages'])) {
                $messages = array_merge($messages, (array) $data['messages']);
            }
            // per-column validation messages (col => msg), excluding reserved keys
            if (($data['error'] ?? '') === 'Validation error') {
                foreach ($data as $k => $v) {
                    if (is_string($k) && is_string($v) && !in_array($k, ['error', 'ids', 'count', 'deleted'], true)) {
                        $messages[] = "{$k}: {$v}";
                    }
                }
            }
        }

        $errors = array_values(array_unique(array_filter($errors, 'is_string')));
        $messages = array_values(array_unique(array_filter($messages, 'is_string')));

        $isError = !in_array($status, ['success', 'data'], true);

        if ($isError) {
            // For partial deletes, surface which ids were successfully removed so
            // the batch caller can see partial progress.
            if ($status === 'mixed' && is_array($data) && isset($data['deleted'])) {
                $messages[] = 'Deleted: ' . implode(',', (array) $data['deleted']);
            }
            $text = self::failureText($errors, $messages);
            return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
        }

        return [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES)]],
            'isError' => false,
        ];
    }

    private static function failureText(array $errors, array $messages): string
    {
        $all = array_merge($errors, $messages);
        $joined = implode('; ', $all);
        // friendlier RBAC phrasing
        if (stripos($joined, 'permission denied') !== false) {
            return 'Not permitted (RBAC) — your CRM role cannot perform this operation. ' . $joined;
        }
        return $joined !== '' ? $joined : 'Operation failed.';
    }

    /**
     * Per-request catalog memo, keyed weakly by session so a fresh session
     * (new rights) never sees another session's catalog and entries die with
     * their session. Shared across tool instances: composite tools (crmx_find,
     * crmx_my_day) hit catalog() once per entity they touch, and the full
     * MetaCatalog build (every Peer/TableMap + 5 RBAC checks per entity) is
     * the single most expensive step of each hit.
     * @var \WeakMap<AuthySession, array>|null
     */
    private static ?\WeakMap $catalogMemo = null;

    /** Build the RBAC-filtered entity/field catalog (override in tests). */
    protected function catalog(AuthySession $session): array
    {
        self::$catalogMemo ??= new \WeakMap();
        if (!isset(self::$catalogMemo[$session])) {
            self::$catalogMemo[$session] = $this->catalogCrossRequest($session);
        }
        return self::$catalogMemo[$session];
    }

    /**
     * Cross-request layer: the catalog is a pure function of (schema, rights).
     * Keyed by the routes file mtime (changes on every rebuild/deploy) + user
     * identity. Rights edits appear within GC_CATALOG_CACHE_TTL (default 300s,
     * 0 disables); real per-op RBAC is enforced downstream regardless — this
     * only shapes what crm_describe advertises.
     */
    private function catalogCrossRequest(AuthySession $session): array
    {
        $routesFile = _BASE_DIR . 'config/Built/settings.routes.php';
        $build = function () use ($routesFile, $session): array {
            $routes = require $routesFile;
            $entityNames = array_keys($routes['json']['GET'] ?? []);
            return (new \ApiGoat\Api\MetaCatalog($session))->build($entityNames);
        };

        $ttl = self::catalogTtl();
        if ($ttl <= 0) {
            return $build();
        }
        $key = 'gc:catalog:' . \md5(
            $routesFile . ':' . (string) @\filemtime($routesFile)
            . ':u' . (int) ($session->get('id') ?? 0)
            . ':a' . (int) (bool) $session->isAdmin()
        );
        return \ApiGoat\Utility\MicroCache::remember($key, $ttl, $build);
    }

    /** GC_CATALOG_CACHE_TTL seconds; default 300; 0 disables. */
    private static function catalogTtl(): int
    {
        $v = \function_exists('env') ? env('GC_CATALOG_CACHE_TTL') : \getenv('GC_CATALOG_CACHE_TTL');
        return ($v === false || $v === null || $v === '') ? 300 : \max(0, (int) $v);
    }

    /** Throw ToolError unless $entity is in the catalog and $op (read/create/update/delete) is permitted. */
    protected function assertEntityPermitted(array $catalog, string $entity, string $op): void
    {
        $entities = $catalog['entities'] ?? [];
        if (!isset($entities[$entity])) {
            throw new ToolError(
                "Unknown or not-permitted entity '{$entity}' — call crm_describe to list entities you may access.",
                [],
                'not_found'
            );
        }
        $perms = $entities[$entity]['permissions'] ?? [];
        if ($op !== '' && empty($perms[$op])) {
            throw new ToolError("Not permitted: your CRM role cannot {$op} {$entity}.", [], 'not_permitted');
        }
    }

    /**
     * Dispatch a synthetic request through the generated {Entity}Service and
     * decode the JSON envelope. Overridable in unit tests. Runs in the real
     * MCP request context (McpEndpoint passes a PSR-7 request/response down).
     */
    protected function dispatch(string $entity, array $request): array
    {
        $svc = self::resolveService($entity, $request);
        $response = $svc->getApiResponse();
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded)
            ? $decoded
            : ['status' => 'failure', 'errors' => ['Unreadable service response']];
    }

    /** Common synthetic-request fields every verb needs. RouteHelper-equivalent shape. */
    protected static function baseRequest(string $entity, string $method): array
    {
        return [
            'method' => $method,
            'p' => $entity,
            'routeName' => $entity,
            'isApiCall' => true,
            'a' => '',
            'i' => '',
            'rbac_public' => '',   // anything != 'passed' so Api::authorize() runs
            'ui' => '',
        ];
    }

    /** Reject non-writable keys and bad enum values up front (mirrors Api::isWritableColumn + crm_describe). */
    protected function assertWritable(array $catalog, string $entity, array $data): void
    {
        $fields = $catalog['entities'][$entity]['fields'] ?? [];
        $writable = [];
        foreach ($fields as $name => $def) {
            if (!empty($def['writable'])) {
                $writable[] = $name;
            }
        }
        foreach ($data as $key => $value) {
            if (!isset($fields[$key]) || empty($fields[$key]['writable'])) {
                throw new ToolError(
                    "Field '{$key}' is not writable on {$entity}.",
                    ['Writable fields: ' . implode(', ', $writable)],
                    'validation'
                );
            }
            $def = $fields[$key];
            if (($def['type'] ?? '') === 'enum' && isset($def['enum']) && is_array($def['enum'])
                && $value !== null && !in_array($value, $def['enum'], true)) {
                throw new ToolError(
                    "Invalid value for '{$key}': must be one of " . implode(', ', $def['enum']) . '.',
                    [], 'validation'
                );
            }
        }
    }

    /**
     * Validate the optional 'lang' argument against the configured locales.
     * Returns the normalized locale, or null when absent (locale-less
     * behavior: writes fan out to every locale, reads use the record's lang).
     */
    protected function assertValidLang(array $args): ?string
    {
        $lang = trim((string) ($args['lang'] ?? ''));
        if ($lang === '') {
            return null;
        }
        $supported = $_SESSION[_AUTH_VAR]->config['locale']['supported_locale'] ?? null;
        if (!\ApiGoat\Api\Api::isAllowedI18nLocale($lang, $supported)) {
            $hint = (is_array($supported) && $supported !== [])
                ? 'Supported: ' . implode(', ', $supported) . '.'
                : 'Expected a ll_CC tag like fr_CA.';
            throw new ToolError("Unsupported lang '{$lang}'. {$hint}", [], 'validation');
        }
        return $lang;
    }

    /**
     * The caller's own language (authy.language), used as the read-locale
     * fallback when neither an explicit 'lang' nor a record language exists.
     * Null when the column/user is absent (older schemas, CLI contexts).
     */
    protected function userLocale(AuthySession $session): ?string
    {
        if (empty($session->authyId) || !class_exists('\App\AuthyQuery')) {
            return null;
        }
        $authy = \App\AuthyQuery::create()->findPk($session->authyId);
        if ($authy === null || !method_exists($authy, 'getLanguage')) {
            return null;
        }
        $v = (string) $authy->getLanguage();
        return $v !== '' ? $v : null;
    }

    /**
     * Append add_i18n column values to a fetched row. The generic view SQL
     * cannot select i18n columns off the base table (they live in
     * {table}_i18n), so crm_get rows silently lacked Terms/Notes/Description.
     * Values read through the model's proxy getters at $lang (default: the
     * row's own lang column, else $fallbackLang — the caller's user language —
     * else the behavior default), falling back to fr_CA like the document
     * renderers.
     *
     * @param mixed $data the envelope 'data' (single assoc row expected)
     */
    protected function mergeI18nColumns(string $entity, $id, $data, ?string $lang, ?string $fallbackLang = null)
    {
        if (!is_array($data) || $data === [] || isset($data[0])) {
            return $data; // not a single row
        }
        $peer  = "\\App\\{$entity}I18nPeer";
        $query = "\\App\\{$entity}Query";
        if (!class_exists($peer) || !method_exists($peer, 'getFieldNames') || !class_exists($query)) {
            return $data;
        }
        // filterByPrimaryKey()->findOne(), NOT findPk(): the tenant/ACL query
        // behaviors only apply through doSelect (see AuthySession::loadPkScoped).
        $obj = $query::create()->filterByPrimaryKey($id)->findOne();
        if (!$obj || !method_exists($obj, 'setLocale')) {
            return $data;
        }
        $locale = $lang;
        if ($locale === null && method_exists($obj, 'getLang') && (string) $obj->getLang() !== '') {
            $locale = (string) $obj->getLang();
        }
        $locale ??= $fallbackLang;
        foreach ($peer::getFieldNames() as $phpName) {
            if ($phpName === 'Locale' || $phpName === 'Id' . $entity) {
                continue;
            }
            $getter = 'get' . $phpName;
            if (!method_exists($obj, $getter)) {
                continue;
            }
            if ($locale !== null) {
                $obj->setLocale($locale);
            }
            $v = (string) $obj->$getter();
            if ($v === '' && $locale !== null && $locale !== 'fr_CA') {
                $obj->setLocale('fr_CA');
                $v = (string) $obj->$getter();
            }
            $key = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $phpName));
            if (!array_key_exists($key, $data)) {
                $data[$key] = $v;
            }
        }
        return $data;
    }

    /**
     * List-shaped counterpart of mergeI18nColumns: append add_i18n column
     * values to every row of a crm_list result in ONE extra query (no per-row
     * model loads). Translation rows are read for all listed ids at once and
     * each row resolves its locale as $lang → the row's own lang column →
     * $fallbackLang → fr_CA, with empty values falling back to fr_CA like the
     * document renderers. Rows lacking the primary-key column (custom select
     * projections, aggregates) are left untouched. Safe on ids the caller can
     * see only: they came out of the ACL-filtered list query itself.
     *
     * @param mixed $data the envelope 'data' (numeric array of assoc rows expected)
     */
    protected function mergeI18nColumnsIntoRows(string $entity, $data, ?string $lang, ?string $fallbackLang = null)
    {
        if (!is_array($data) || $data === [] || !isset($data[0]) || !is_array($data[0])) {
            return $data; // not a list of rows
        }
        $peer      = "\\App\\{$entity}I18nPeer";
        $i18nQuery = "\\App\\{$entity}I18nQuery";
        if (!class_exists($peer) || !method_exists($peer, 'getFieldNames') || !class_exists($i18nQuery)) {
            return $data;
        }
        $fkPhp = 'Id' . $entity;
        $pkKey = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $fkPhp));

        $ids = [];
        foreach ($data as $row) {
            if (is_array($row) && isset($row[$pkKey]) && $row[$pkKey] !== '') {
                $ids[] = $row[$pkKey];
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return $data;
        }

        // Only true i18n content columns: skip the bookkeeping pair AND any
        // column the MAIN table also has (tablestamps live on both; the row's
        // own values must win, mirroring the single-row proxy-getter path).
        $mainPeer   = "\\App\\{$entity}Peer";
        $mainFields = (class_exists($mainPeer) && method_exists($mainPeer, 'getFieldNames'))
            ? $mainPeer::getFieldNames()
            : [];
        $cols = [];
        foreach ($peer::getFieldNames() as $phpName) {
            if ($phpName !== 'Locale' && $phpName !== $fkPhp && !in_array($phpName, $mainFields, true)) {
                $cols[$phpName] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $phpName));
            }
        }
        if ($cols === []) {
            return $data;
        }

        // id → locale → phpName → value, one IN() query for the whole page.
        $byId = [];
        foreach ($i18nQuery::create()->filterBy($fkPhp, $ids, \Criteria::IN)->find() as $tr) {
            $rowId = $tr->getByName($fkPhp);
            $loc   = (string) $tr->getByName('Locale');
            foreach ($cols as $phpName => $snake) {
                $byId[$rowId][$loc][$phpName] = (string) $tr->getByName($phpName);
            }
        }

        foreach ($data as &$row) {
            if (!is_array($row) || !isset($row[$pkKey]) || $row[$pkKey] === '') {
                continue;
            }
            $locales = $byId[$row[$pkKey]] ?? [];
            $locale  = $lang
                ?? ((isset($row['lang']) && is_string($row['lang']) && $row['lang'] !== '') ? $row['lang'] : null)
                ?? $fallbackLang
                ?? 'fr_CA';
            foreach ($cols as $phpName => $snake) {
                if (array_key_exists($snake, $row)) {
                    continue;
                }
                $v = (string) ($locales[$locale][$phpName] ?? '');
                if ($v === '' && $locale !== 'fr_CA') {
                    $v = (string) ($locales['fr_CA'][$phpName] ?? '');
                }
                $row[$snake] = $v;
            }
        }
        unset($row);
        return $data;
    }

    /** Reject a create missing required writable fields (mirrors crm_describe's 'required' flag). */
    protected function assertRequired(array $catalog, string $entity, array $data): void
    {
        $fields = $catalog['entities'][$entity]['fields'] ?? [];
        $required = [];
        foreach ($fields as $name => $def) {
            if (!empty($def['required']) && !empty($def['writable'])) {
                $required[] = $name;
            }
        }
        $missing = array_values(array_filter($required, fn ($name) => !isset($data[$name]) || $data[$name] === ''));
        if ($missing) {
            throw new ToolError(
                "Missing required field(s) for {$entity}: " . implode(', ', $missing)
                . '. Ask the user for these values — never invent them.',
                ['Required fields: ' . implode(', ', $required)],
                'validation'
            );
        }
    }

    /** \App\{Entity}ServiceWrapper → \App\{Entity}Service, both ($request,$response,$args). */
    protected static function resolveService(string $entity, array $args)
    {
        $request  = $GLOBALS['__mcp_request']  ?? null;   // set by McpEndpoint for the in-process call
        // FRESH response for the in-process service — it writes its Api envelope to this
        // body, which we read back and discard. Reusing McpEndpoint's response would
        // concatenate the envelope with the JSON-RPC result, corrupting the HTTP body.
        $response = new \Slim\Psr7\Response();
        $wrapper  = "\\App\\{$entity}ServiceWrapper";
        $bare     = "\\App\\{$entity}Service";
        if (class_exists($wrapper)) {
            return new $wrapper($request, $response, $args);
        }
        if (class_exists($bare)) {
            return new $bare($request, $response, $args);
        }
        throw new ToolError("No service for entity '{$entity}'.", [], 'bad_request');
    }
}
