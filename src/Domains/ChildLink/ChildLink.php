<?php

namespace ApiGoat\Domains\ChildLink;

use ApiGoat\Sessions\AuthySession;

/**
 * Link/unlink an EXISTING row into a parent's child list (the `set_child_link`
 * GoatCheese behavior). Where the standard child list "Add" creates a new row
 * and its row "Delete" destroys one, a linked child list ASSOCIATES existing
 * rows instead: Add becomes a search picker that sets the child's FK to the
 * parent (plus optional `set` column stamps), and Delete becomes Remove —
 * the FK is NULLed (plus optional `unset` stamps) and the row lives on.
 * Canonical use: team membership (business_account <- authy.id_business_account).
 *
 * Three entry points, all driven by the emitted per-child $cfg
 * (childPhp => ['fk' => FkPhp, 'show' => [ColPhp...], 'set' => [ColPhp=>lit],
 * 'unset' => [ColPhp=>lit], 'labels' => [...]]):
 *   - search(): candidates matching a term (JSON rows for the picker).
 *   - link():   point one child row at the parent.
 *   - unlink(): detach one child row from the parent.
 *
 * Security: the caller needs 'w' rights on BOTH models (parent + child) —
 * nothing is ever deleted, so 'd' is deliberately not required. Row loads all
 * go through AuthySession::loadPkScoped (tenant hard-partition + Owner/Group
 * scope for 'w'); the candidate search applies the same scoping inline.
 * unlink() refuses when the row is not currently linked to the parent whose
 * form issued the request, so a crafted request can't detach rows elsewhere.
 */
class ChildLink
{
    /** '\App\AuthyQuery' from a child PhpName. */
    private static function queryClass(string $childPhp): string
    {
        return '\\App\\' . $childPhp . 'Query';
    }

    /** Rights gate shared by all three actions. */
    private static function allowed(string $parentModel, string $childPhp, AuthySession $session): bool
    {
        if ($session->isRoot()) {
            return true;
        }
        return $session->hasRights($parentModel, 'w') && $session->hasRights($childPhp, 'w');
    }

    /** Resolve + sanity-check the per-child config for a request. */
    private static function childCfg(array $cfg, $request): ?array
    {
        $child = (string) ($request['child'] ?? '');
        if ($child === '' || !isset($cfg[$child]) || !is_array($cfg[$child])) {
            return null;
        }
        return ['child' => $child] + $cfg[$child];
    }

    /**
     * Picker candidates: child rows matching $term on the configured `show`
     * columns (OR LIKE), excluding rows already linked to THIS parent. Rows
     * linked to a DIFFERENT parent are returned flagged (linked=1) so the
     * client can warn before reassigning.
     *
     * @return array{rows: array<int, array{id: mixed, label: string, linked: int}>}
     */
    public static function search(string $parentModel, array $cfg, $request, AuthySession $session): array
    {
        $out = ['rows' => []];
        $c   = self::childCfg($cfg, $request);
        if ($c === null || !self::allowed($parentModel, $c['child'], $session)) {
            return $out;
        }
        $term = trim((string) ($request['term'] ?? ''));
        if ($term === '') {
            return $out;
        }
        $ip = (int) ($request['ip'] ?? 0);

        $qc = self::queryClass($c['child']);
        $q  = $qc::create();
        $first = true;
        foreach ((array) $c['show'] as $colPhp) {
            if ($first) {
                $q->{'filterBy' . $colPhp}('%' . $term . '%', \Criteria::LIKE);
                $first = false;
            } else {
                $q->_or()->{'filterBy' . $colPhp}('%' . $term . '%', \Criteria::LIKE);
            }
        }
        if ($first) {
            return $out; // no show columns — nothing to match on
        }
        if (! $session->isRoot()) {
            if ($session->get('id_tenant') && method_exists($q, 'filterByIdTenant')) {
                $q->filterByIdTenant($session->get('id_tenant'));
            }
            $session->applyOwnerGroupScope($q, $session->hasRights($c['child'], 'w'));
        }

        $fkGetter = 'get' . $c['fk'];
        foreach ($q->limit(50)->find() as $row) {
            $fkVal = $row->$fkGetter();
            if ($ip && (int) $fkVal === $ip) {
                continue; // already on this parent's list
            }
            $parts = [];
            foreach ((array) $c['show'] as $colPhp) {
                $v = (string) $row->{'get' . $colPhp}();
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
            $out['rows'][] = [
                'id'     => $row->getPrimaryKey(),
                'label'  => implode(' — ', $parts),
                'linked' => $fkVal ? 1 : 0,
            ];
            if (count($out['rows']) >= 20) {
                break;
            }
        }
        return $out;
    }

    /**
     * Link one child row to the parent: FK := parent PK, then the configured
     * `set` stamps. Both rows are loaded scoped ('w') — an out-of-scope pk in
     * either position refuses without leaking existence.
     *
     * @return array{status: string, message: string}
     */
    public static function link(string $parentModel, array $cfg, $request, AuthySession $session): array
    {
        $c = self::childCfg($cfg, $request);
        if ($c === null || !self::allowed($parentModel, $c['child'], $session)) {
            return ['status' => 'error', 'message' => 'not authorized'];
        }
        $ip = (int) ($request['ip'] ?? 0);
        $pk = json_decode((string) ($request['i'] ?? ''), true);
        if (!$ip || $pk === null) {
            return ['status' => 'error', 'message' => 'missing id'];
        }
        $parent = $session->loadPkScoped('\\App\\' . $parentModel . 'Query', $ip, $parentModel, 'w');
        if ($parent === null) {
            return ['status' => 'error', 'message' => 'parent not found'];
        }
        $row = $session->loadPkScoped(self::queryClass($c['child']), $pk, $c['child'], 'w');
        if ($row === null) {
            return ['status' => 'error', 'message' => 'record not found'];
        }
        $row->{'set' . $c['fk']}($ip);
        foreach ((array) ($c['set'] ?? []) as $colPhp => $lit) {
            $row->{'set' . $colPhp}($lit);
        }
        try {
            $row->save();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
        return ['status' => 'success', 'message' => ''];
    }

    /**
     * Unlink one child row from the parent: FK := NULL, then the configured
     * `unset` stamps. Refuses when the row is not currently linked to $ip —
     * the request's parent context must match the actual association.
     *
     * @return array{status: string, message: string}
     */
    public static function unlink(string $parentModel, array $cfg, $request, AuthySession $session): array
    {
        $c = self::childCfg($cfg, $request);
        if ($c === null || !self::allowed($parentModel, $c['child'], $session)) {
            return ['status' => 'error', 'message' => 'not authorized'];
        }
        $ip = (int) ($request['ip'] ?? 0);
        $pk = json_decode((string) ($request['i'] ?? ''), true);
        if (!$ip || $pk === null) {
            return ['status' => 'error', 'message' => 'missing id'];
        }
        $row = $session->loadPkScoped(self::queryClass($c['child']), $pk, $c['child'], 'w');
        if ($row === null) {
            return ['status' => 'error', 'message' => 'record not found'];
        }
        if ((int) $row->{'get' . $c['fk']}() !== $ip) {
            return ['status' => 'error', 'message' => 'not linked to this record'];
        }
        $row->{'set' . $c['fk']}(null);
        foreach ((array) ($c['unset'] ?? []) as $colPhp => $lit) {
            $row->{'set' . $colPhp}($lit);
        }
        try {
            $row->save();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
        return ['status' => 'success', 'message' => ''];
    }

    /**
     * The client-side onReadyJs interceptor for a parent form's linked child
     * lists. A window-capture click listener (bound once per parent) pre-empts
     * the template handlers: the child list's Add button opens a search picker
     * (childLinkSearch -> childLinkSave) instead of a create form, and the row
     * delete link becomes a Remove confirm (childUnlink) instead of a delete.
     * Scoped to child wrappers whose data-parent matches — the child model's
     * own standalone list (and the same child under another parent) keeps its
     * stock behavior. Kept here (not in the emitter) so the JS is a single,
     * single-escaped PHP string emitted by CALL — same pattern as
     * DateCascadeDelete::interceptorScript.
     *
     * $clientCfg: childPhp => {addTitle, searchPlaceholder, empty, linkedNote,
     * reassignConfirm, removeConfirm, removeLabel, addedToast, removedToast}.
     */
    public static function interceptorScript(string $parentModel, array $clientCfg): string
    {
        $js = <<<'JS'
(function(){
    if (window.__gcChildLink_%PARENT%) { return; }
    window.__gcChildLink_%PARENT% = 1;
    var PARENT = '%PARENT%', CFG = %CFG%;
    function ensureStyle(){
        if (document.getElementById('gc-cl-style')) { return; }
        var st = document.createElement('style'); st.id = 'gc-cl-style';
        st.textContent = '.gc-cl-pop{position:relative;background:var(--sw-surface,#fff);border:1px solid rgba(0,0,0,.15);border-radius:8px;padding:10px;margin:8px 0;box-shadow:0 4px 16px rgba(0,0,0,.12)}'
            + '.gc-cl-head{display:flex;justify-content:space-between;align-items:center;font-weight:600;margin-bottom:6px}'
            + '.gc-cl-head a{text-decoration:none;font-size:18px;line-height:1;color:inherit;opacity:.6}'
            + '.gc-cl-input{width:100%;padding:6px 8px;box-sizing:border-box}'
            + '.gc-cl-results{max-height:220px;overflow:auto;margin-top:6px}'
            + '.gc-cl-row{padding:6px 8px;cursor:pointer;border-radius:6px}'
            + '.gc-cl-row:hover{background:rgba(0,0,0,.06)}'
            + '.gc-cl-note{opacity:.65;font-size:.85em;margin-left:6px}'
            + '.gc-cl-empty{padding:6px 8px;opacity:.7}';
        document.head.appendChild(st);
    }
    function xhrHeaders(){ return {'X-Requested-With':'XMLHttpRequest'}; }
    function refresh(cw){
        var parent = cw.getAttribute('data-parent') || PARENT,
            child = cw.getAttribute('data-model'),
            ip = cw.getAttribute('data-ip') || '',
            ui = cw.getAttribute('data-ui') || 'editDialog';
        fetch(_SITE_URL + parent + '/' + child + '?i=' + encodeURIComponent(ip) + '&ui=' + encodeURIComponent(ui),
            {credentials:'same-origin', headers:xhrHeaders()})
        .then(function(r){ return r.text(); }).then(function(t){
            var tmp = document.createElement('div'); tmp.innerHTML = t;
            var fresh = tmp.querySelector('.va-mob.proto-app[data-model]');
            if (fresh && cw.parentNode) {
                cw.parentNode.replaceChild(fresh, cw);
                try { document.dispatchEvent(new CustomEvent('gc:list-refreshed', {detail:{model:child, parent:parent, ip:ip}})); } catch(_){}
            }
        }).catch(function(){});
    }
    function post(action, params){
        var body = Object.keys(params).map(function(k){ return k + '=' + encodeURIComponent(params[k]); }).join('&');
        return fetch(_SITE_URL + PARENT + '/' + action, {
            method:'POST', credentials:'same-origin',
            headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body
        }).then(function(r){ return r.json(); });
    }
    function closePop(){ var p = document.getElementById('gc-cl-pop'); if (p && p.parentNode) { p.parentNode.removeChild(p); } }
    function doLink(cw, childKey, id, linked, c){
        var go = function(){
            post('childLinkSave', {child:childKey, i:JSON.stringify(id), ip:cw.getAttribute('data-ip')||''}).then(function(j){
                if (j && j.status === 'success') {
                    closePop();
                    if (window.gcScreens && gcScreens.toast) { gcScreens.toast(c.addedToast || 'Added'); }
                    refresh(cw);
                } else if (window.gcScreens && gcScreens.alert) {
                    gcScreens.alert('Error', (j && j.message) || 'Could not add');
                }
            }).catch(function(){});
        };
        if (linked) {
            var q = c.reassignConfirm || 'Already assigned elsewhere — reassign it here?';
            Promise.resolve((window.gcScreens && gcScreens.confirm) ? gcScreens.confirm(q) : window.confirm(q))
                .then(function(ok){ if (ok) { go(); } });
        } else { go(); }
    }
    function openPicker(cw, childKey){
        ensureStyle(); closePop();
        var c = CFG[childKey] || {};
        var pop = document.createElement('div');
        pop.id = 'gc-cl-pop'; pop.className = 'gc-cl-pop';
        var head = document.createElement('div'); head.className = 'gc-cl-head';
        head.appendChild(document.createTextNode(c.addTitle || 'Link existing'));
        var x = document.createElement('a'); x.href = 'Javascript:'; x.innerHTML = '&times;';
        x.addEventListener('click', closePop); head.appendChild(x);
        var input = document.createElement('input'); input.type = 'text';
        input.className = 'gc-cl-input'; input.placeholder = c.searchPlaceholder || 'Search…';
        var res = document.createElement('div'); res.className = 'gc-cl-results';
        pop.appendChild(head); pop.appendChild(input); pop.appendChild(res);
        cw.insertAdjacentElement('afterbegin', pop);
        var tId = null;
        input.addEventListener('input', function(){
            clearTimeout(tId);
            var v = input.value.trim();
            tId = setTimeout(function(){
                if (!v) { res.innerHTML = ''; return; }
                fetch(_SITE_URL + PARENT + '/childLinkSearch?child=' + encodeURIComponent(childKey)
                        + '&term=' + encodeURIComponent(v) + '&ip=' + encodeURIComponent(cw.getAttribute('data-ip')||''),
                    {credentials:'same-origin', headers:xhrHeaders()})
                .then(function(r){ return r.json(); }).then(function(j){
                    res.innerHTML = '';
                    var rows = (j && j.rows) || [];
                    if (!rows.length) {
                        var em = document.createElement('div'); em.className = 'gc-cl-empty';
                        em.textContent = c.empty || 'No match'; res.appendChild(em); return;
                    }
                    rows.forEach(function(r0){
                        var d = document.createElement('div'); d.className = 'gc-cl-row';
                        d.appendChild(document.createTextNode(r0.label));
                        if (r0.linked) {
                            var n = document.createElement('span'); n.className = 'gc-cl-note';
                            n.textContent = c.linkedNote || 'already assigned'; d.appendChild(n);
                        }
                        d.addEventListener('click', function(){ doLink(cw, childKey, r0.id, r0.linked, c); });
                        res.appendChild(d);
                    });
                }).catch(function(){});
            }, 250);
        });
        try { input.focus(); } catch(_){}
    }
    window.addEventListener('click', function(e){
        for (var childKey in CFG) {
            var add = e.target.closest('#add' + childKey);
            if (add) {
                var cw = add.closest('.va-mob.proto-app[data-model]');
                if (!cw || cw.getAttribute('data-parent') !== PARENT) { return; }
                e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
                openPicker(cw, childKey); return;
            }
            var del = e.target.closest("[j='delete" + childKey + "']");
            if (del) {
                var cw2 = del.closest('.va-mob.proto-app[data-model]');
                if (!cw2 || cw2.getAttribute('data-parent') !== PARENT) { return; }
                e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
                var c = CFG[childKey] || {}, pk = del.getAttribute('i') || '';
                var q = c.removeConfirm || 'Remove this entry from the list? The record itself is not deleted.';
                Promise.resolve((window.gcScreens && gcScreens.confirm)
                        ? gcScreens.confirm(q, {confirmLabel: c.removeLabel || 'Remove', danger: true})
                        : window.confirm(q))
                .then(function(ok){
                    if (!ok) { return; }
                    post('childUnlink', {child:childKey, i:pk, ip:cw2.getAttribute('data-ip')||''}).then(function(j){
                        if (j && j.status === 'success') {
                            if (window.gcScreens && gcScreens.toast) { gcScreens.toast(c.removedToast || 'Removed'); }
                            refresh(cw2);
                        } else if (window.gcScreens && gcScreens.alert) {
                            gcScreens.alert('Error', (j && j.message) || 'Could not remove');
                        }
                    }).catch(function(){});
                });
                return;
            }
        }
    }, true);
})();
JS;
        return str_replace(
            ['%PARENT%', '%CFG%'],
            [$parentModel, json_encode($clientCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            $js
        );
    }
}
