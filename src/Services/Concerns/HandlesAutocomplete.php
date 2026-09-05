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

        // Ownership scope: when the caller's read right on the FK target is
        // Owner/Group-scoped, autocomplete only the rows they may access
        // (mirrors Api::setAclFilter). method_exists guards mean ungoverned
        // reference tables (no id_creation column, e.g. Country) and "All"
        // rights are unaffected — browse stays open there.
        $s = $_SESSION[_AUTH_VAR] ?? null;
        // Tenant row-scoping: mirror AuthyACL::setAclFilter so autocomplete can't
        // surface FK-target rows from other tenants (non-root users).
        if (is_object($s) && method_exists($s, 'get')
            && !$s->get('isRoot') && $s->get('id_tenant')
            && method_exists($Model, 'filterByIdTenant')) {
            $q->filterByIdTenant($s->get('id_tenant'));
        }
        if (is_object($s) && method_exists($s, 'hasRights')) {
            $scope = $s->hasRights($fkt, 'r');
            if (is_array($scope)) {
                if (in_array('Owner', $scope, true) && method_exists($Model, 'filterByIdCreation')) {
                    $q->filterByIdCreation($s->getIdAuthy());
                    if (in_array('Group', $scope, true) && method_exists($Model, 'filterByIdGroupCreation')) {
                        $q->_or()->filterByIdGroupCreation($s->getGroups(), \Criteria::IN);
                    }
                } elseif (in_array('Group', $scope, true) && method_exists($Model, 'filterByIdGroupCreation')) {
                    $q->filterByIdGroupCreation($s->getGroups(), \Criteria::IN);
                }
            }
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
}
