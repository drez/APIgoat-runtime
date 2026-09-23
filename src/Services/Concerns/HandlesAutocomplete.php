<?php

declare(strict_types=1);

namespace ApiGoat\Services\Concerns;

/**
 * The generic `set_autocomplete` request handler, shared by every generated
 * service that declares the behavior.
 *
 * It used to be emitted verbatim into each service (~140 identical lines x 73
 * services across the fleet). Only the build-time allowlist is table-specific,
 * so the emitter now keeps emitting just `autocAllowlist()` (the per-table
 * var_export) plus `use HandlesAutocomplete;`, and the logic lives here.
 *
 * Contract with the host class (all already present on a generated service):
 *   - `$this->request`               the merged route args array
 *   - `autocAllowlist(): array`      build-time allowlist, keyed by FK model
 *                                    php name => ['cols'=>[], 'ids'=>[], 'where'=>[]]
 *
 * The HTTP contract is unchanged: the client (template
 * `public/js/app/autocomplete.js`, via `wrap_autoc`'s `a:"autoc"`) still gets
 * `{data:[{show,id}], count, status}` and the same `error` strings.
 */
trait HandlesAutocomplete
{
    function autocomplete()
    {
        $body = ['data' => [], 'count' => 0, 'status' => 'error'];

        $fkt    = $this->request['data']['fkt']    ?? null;
        $show   = $this->request['data']['show']   ?? null;
        $id     = $this->request['data']['id']     ?? null;
        $filter = $this->request['data']['filter'] ?? null;
        $str    = $this->request['data']['str']    ?? '';
        $limit  = (int)($this->request['data']['limit'] ?? 20);
        if ($limit <= 0 || $limit > 100) { $limit = 20; }

        if (!$fkt || !$id || !$filter || !$show) {
            $body['error'] = 'missing parameter';
            return $body;
        }
        if (!is_array($show)) {
            $show = array_values(array_filter(array_map('trim', explode(',', (string)$show)), 'strlen'));
        }
        if (!$show) {
            $body['error'] = 'empty show';
            return $body;
        }

        // SECURITY: require an authenticated session and bind fkt/show/id to the
        // build-time allowlist (autocAllowlist) of THIS form's own autocompletes.
        // Without this a logged-in user could select arbitrary columns of any
        // model (e.g. Authy.PasswdHash).
        if (!is_object($_SESSION[_AUTH_VAR] ?? null)) {
            $body['error'] = 'unauthenticated';
            return $body;
        }
        $allow = $this->autocAllowlist();
        if (!isset($allow[$fkt])) {
            $body['error'] = 'table not allowed';
            return $body;
        }
        $show = array_values(array_intersect($show, $allow[$fkt]['cols']));
        if (!$show) {
            $body['error'] = 'no allowed columns';
            return $body;
        }
        if (!in_array($id, $allow[$fkt]['ids'], true)) {
            $body['error'] = 'id not allowed';
            return $body;
        }

        $Model = '\\App\\' . $fkt . 'Query';
        if (!class_exists($Model)) {
            $body['error'] = 'unknown table';
            return $body;
        }

        $select = array_values(array_unique(array_merge($show, [$id])));
        $q = $Model::create()->select($select)->orderBy($show[0], 'ASC');

        // The text search may only filter on a column the picker actually
        // shows. Restricting to $show stops a crafted request from using
        // filter[] as a value-confirmation oracle on arbitrary columns.
        if (is_array($filter)) {
            foreach ($filter as $field => $val) {
                if (!in_array($field, $show, true)) { continue; }
                $method = 'filterBy' . $field;
                if (!method_exists($Model, $method)) { continue; }
                if (is_array($val)) {
                    $q->$method('%' . $val[0] . '%');
                    if (($val[1] ?? null) === 'or') { $q->_or(); }
                } else {
                    $q->$method('%' . $val . '%');
                }
            }
        } else {
            if (in_array($filter, $show, true)) {
                $method = 'filterBy' . $filter;
                if (method_exists($Model, $method)) {
                    $q->$method('%' . $str . '%');
                }
            }
        }

        // depends_on constraints: equality filters on the FK table, sent as
        // where{ColPhpName: value}. Empty values are ignored so an unset
        // source field leaves the lookup unconstrained. Bound to the columns
        // THIS form's autocompletes declare as depends_on sources (allowlist
        // 'where') so a crafted request can't equality-probe arbitrary FK-model
        // columns (a value-confirmation oracle) — the same guard $filter has.
        $where = $this->request['data']['where'] ?? null;
        if (is_array($where)) {
            $allowWhere = $allow[$fkt]['where'] ?? [];
            foreach ($where as $field => $val) {
                $val = trim((string)$val);
                if ($val === '') { continue; }
                $fieldPhp = preg_replace('/[^A-Za-z0-9_]/', '', (string)$field);
                if (!in_array($fieldPhp, $allowWhere, true)) { continue; }
                $method = 'filterBy' . $fieldPhp;
                if (method_exists($Model, $method)) {
                    $q->$method($val);
                }
            }
        }

        // Row scope: the SAME reference-access rule as the FK dropdown
        // (AuthySession::applyReferenceScope, owner decision 2026-09-23) —
        // always tenant-partitioned; an 'r' grant on the target keeps its own
        // All/Owner/Group scope; without 'r', rows are listed (id + label only)
        // only when the user can w/a the FORM's model and the column is
        // reference-allowed (auth/group targets need pick_users). The form
        // model + allowed flag come from the build-time allowlist ('refs'),
        // never from the request. The old inline copy treated "no 'r'" as
        // "every row", so any logged-in user could enumerate e.g. Authy.
        $s = $_SESSION[_AUTH_VAR] ?? null;
        if (is_object($s) && method_exists($s, 'applyReferenceScope')) {
            $ref = $this->autocReferenceTuple($s, (string)$fkt, $Model, $allow[$fkt]);
            $s->applyReferenceScope($q, $ref['target'], $ref['form'], $ref['allowed']);
        } else {
            $q->where('1 = 0');
        }

        $q->limit($limit);
        $results = $q->find();

        foreach ($results as $row) {
            $row = (array)$row;
            $parts = [];
            foreach ($show as $f) {
                if (isset($row[$f]) && strlen((string)$row[$f])) { $parts[] = $row[$f]; }
            }
            $body['data'][] = ['show' => implode(' ', $parts), 'id' => $row[$id] ?? null];
        }
        $body['count'] = count($body['data']);
        $body['status'] = 'success';
        return $body;
    }

    /**
     * The (target, form, allowed) reference tuple for this lookup, from the
     * build-time allowlist. Several autocomplete columns of one form may point
     * at the same target: the first tuple that grants reference access to
     * this session wins (an 'r' grant on the target is decided by
     * applyReferenceScope itself). An allowlist baked before 'refs' existed
     * gets the same decision derived server-side: the form is this service's
     * own model, the auth/group tables are not reference data.
     *
     * @return array{target:string,form:string,allowed:bool}
     */
    protected function autocReferenceTuple($session, string $fkt, string $queryClass, array $entry): array
    {
        $refs = [];
        foreach ((array)($entry['refs'] ?? []) as $r) {
            if (is_array($r) && isset($r['form'])) {
                $refs[] = [
                    'target'  => (string)($r['target'] ?? ''),
                    'form'    => (string)$r['form'],
                    'allowed' => ($r['allowed'] ?? false) === true,
                ];
            }
        }
        if (!$refs) {
            $owned = method_exists($queryClass, 'filterByIdCreation') || method_exists($queryClass, 'filterByIdGroupCreation');
            $isAuth = in_array($fkt, ['Authy', 'AuthyGroup'], true) || method_exists($queryClass, 'filterByPasswdHash');
            $short = (new \ReflectionClass($this))->getShortName();
            $refs[] = [
                'target'  => $owned ? $fkt : '',
                'form'    => (string)preg_replace('/Service$/', '', $short),
                'allowed' => !$isAuth,
            ];
        }
        foreach ($refs as $r) {
            if ($r['allowed'] && method_exists($session, 'canReferenceFrom') && $session->canReferenceFrom($r['form'])) {
                return $r;
            }
        }
        return $refs[0];
    }
}
