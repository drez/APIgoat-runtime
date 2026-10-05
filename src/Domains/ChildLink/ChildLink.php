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
     * `cols` carries the `show` values in order (blanks kept) so the picker can
     * lay a row out as name + meta; `label` stays the joined legacy string.
     *
     * @return array{rows: array<int, array{id: mixed, label: string, cols: string[], linked: int}>}
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
            $session->applyTenantScope($q);
            $session->applyOwnerGroupScope($q, $session->hasRights($c['child'], 'w'));
        }

        $fkGetter = 'get' . $c['fk'];
        foreach ($q->limit(50)->find() as $row) {
            $fkVal = $row->$fkGetter();
            if ($ip && (int) $fkVal === $ip) {
                continue; // already on this parent's list
            }
            $parts = [];
            $cols  = [];
            foreach ((array) $c['show'] as $colPhp) {
                $v = (string) $row->{'get' . $colPhp}();
                $cols[] = $v;
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
            $out['rows'][] = [
                'id'     => $row->getPrimaryKey(),
                'label'  => implode(' — ', $parts),
                'cols'   => $cols,
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
        $oldFk = $row->{'get' . $c['fk']}();
        // Optional project veto before linking (e.g. a seat limit):
        // \App\{Parent}ServiceWrapper::childLinkAllowed($childPhp, $row, $toPk)
        // returns an error message, or null to allow.
        $veto = self::veto($parentModel, $c['child'], $row, $ip);
        if ($veto !== null) {
            return ['status' => 'error', 'message' => $veto];
        }
        $row->{'set' . $c['fk']}($ip);
        foreach ((array) ($c['set'] ?? []) as $colPhp => $lit) {
            $row->{'set' . $colPhp}($lit);
        }
        try {
            $row->save();
        } catch (\Exception $e) {
            // SECURITY: never echo raw DB/driver errors to the client.
            error_log('ChildLink::link save failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'save failed'];
        }
        self::notify($parentModel, $c['child'], $row, $oldFk ? (int) $oldFk : null, $ip);
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
            // SECURITY: never echo raw DB/driver errors to the client.
            error_log('ChildLink::unlink save failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'save failed'];
        }
        self::notify($parentModel, $c['child'], $row, $ip, null);
        return ['status' => 'success', 'message' => ''];
    }

    private static function veto(string $parentModel, string $childPhp, object $row, int $toPk): ?string
    {
        $cls = '\\App\\' . $parentModel . 'ServiceWrapper';
        if (!class_exists($cls) || !is_callable([$cls, 'childLinkAllowed'])) {
            return null;
        }
        try {
            $msg = $cls::childLinkAllowed($childPhp, $row, $toPk);
            return is_string($msg) && $msg !== '' ? $msg : null;
        } catch (\Throwable $e) {
            error_log('ChildLink veto hook failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Project hook after a successful link/unlink: when
     * \App\{Parent}ServiceWrapper declares a static
     * childLinkChanged(string $childPhp, object $row, ?int $fromPk, ?int $toPk),
     * it is called with the saved row and the parent pk it left / joined
     * (reassign = both set). Lets a project keep derived state in step (e.g. a
     * team manager pointer) without forking this helper. Hook failures are
     * logged, never surfaced — the link itself already succeeded.
     */
    private static function notify(string $parentModel, string $childPhp, object $row, ?int $fromPk, ?int $toPk): void
    {
        $cls = '\\App\\' . $parentModel . 'ServiceWrapper';
        if (!class_exists($cls) || !is_callable([$cls, 'childLinkChanged'])) {
            return;
        }
        try {
            $cls::childLinkChanged($childPhp, $row, $fromPk, $toPk);
        } catch (\Throwable $e) {
            error_log('ChildLink hook failed: ' . $e->getMessage());
        }
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
     * The picker opens under the list header and stays open (multi-add: each
     * pick links, marks the row Added and refreshes the list behind it) until
     * Done/Escape. Linked wrappers get data-gc-childlink so the template CSS
     * shows each row's Remove; a member's edit-drawer footer Delete, opened
     * from a linked list, also becomes Remove (never deletes the record).
     * Styles live in the template's _listv2.scss (.gc-cl-*).
     *
     * $clientCfg: childPhp => {addTitle, searchPlaceholder, empty, linkedNote,
     * reassignConfirm, removeConfirm, removeLabel, addedToast, removedToast,
     * doneLabel, addLabel, addedLabel}.
     */
    public static function interceptorScript(string $parentModel, array $clientCfg): string
    {
        $js = <<<'JS'
(function(){
    if (window.__gcChildLink_%PARENT%) { return; }
    window.__gcChildLink_%PARENT% = 1;
    var PARENT = '%PARENT%', CFG = %CFG%;
    // Styles: template _listv2.scss (.gc-cl-*, [data-gc-childlink]) — no inline CSS here.
    function wrapSel(child, ip){
        return ".va-mob.proto-app[data-model='" + child + "'][data-parent='" + PARENT + "']"
            + (ip ? "[data-ip='" + ip + "']" : '');
    }
    function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function xhrHeaders(){ return {'X-Requested-With':'XMLHttpRequest'}; }
    function toast(m){ if (window.gcScreens && gcScreens.toast) { gcScreens.toast(m); } }
    function fail(m){ if (window.gcScreens && gcScreens.alert) { gcScreens.alert('Error', m); } }
    function ask(q, opts){
        return Promise.resolve((window.gcScreens && gcScreens.confirm) ? gcScreens.confirm(q, opts) : window.confirm(q));
    }
    function post(action, params){
        var body = Object.keys(params).map(function(k){ return k + '=' + encodeURIComponent(params[k]); }).join('&');
        return fetch(_SITE_URL + PARENT + '/' + action, {
            method:'POST', credentials:'same-origin',
            headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body
        }).then(function(r){ return r.json(); });
    }
    function pkParam(v){ v = v == null ? '' : String(v); return /^-?\d+$/.test(v) || /^[\[{"]/.test(v) ? v : JSON.stringify(v); }

    // Tag linked child lists (CSS shows their row actions) and turn each
    // row's delete into a visible Remove — injected when the user lacks the
    // child's 'd' right (no stock link rendered), since Remove only needs 'w'.
    function decorate(root){
        if (!root || root.nodeType !== 1) { return; }
        var list = root.matches('.va-mob.proto-app[data-model]') ? [root]
            : root.querySelectorAll('.va-mob.proto-app[data-model]');
        Array.prototype.forEach.call(list, function(cw){
            var child = cw.getAttribute('data-model');
            if (cw.getAttribute('data-parent') !== PARENT || !CFG[child]) { return; }
            var c = CFG[child], label = c.removeLabel || 'Remove';
            cw.setAttribute('data-gc-childlink', '1');
            Array.prototype.forEach.call(cw.querySelectorAll('.va-mob-list .va-mob-row, .va-dt-table tbody tr'), function(row){
                var cell = row.querySelector('.actionrow');
                if (!cell) { return; }
                var a = cell.querySelector('.ac-delete-link');
                if (!a) {
                    a = document.createElement('a');
                    a.href = 'Javascript:;'; a.className = 'ac-delete-link';
                    a.setAttribute('j', 'delete' + child);
                    cell.insertBefore(a, cell.firstChild);
                }
                if (a.getAttribute('data-gc-cl')) { return; }
                a.setAttribute('data-gc-cl', '1');
                a.classList.add('gc-cl-remove');
                a.setAttribute('title', label);
                a.innerHTML = '<i class="ri-user-unfollow-line"></i><span>' + esc(label) + '</span>';
            });
        });
    }
    new MutationObserver(function(muts){
        muts.forEach(function(m){ Array.prototype.forEach.call(m.addedNodes, decorate); });
    }).observe(document.body, {childList:true, subtree:true});
    decorate(document.body);

    // Re-fetch the linked list in place; an open picker is moved into the
    // fresh wrapper so it stays open for the next pick.
    function refresh(child, ip){
        var cw = document.querySelector(wrapSel(child, ip));
        if (!cw) { return; }
        var ui = cw.getAttribute('data-ui') || 'editDialog';
        fetch(_SITE_URL + PARENT + '/' + child + '?i=' + encodeURIComponent(ip) + '&ui=' + encodeURIComponent(ui),
            {credentials:'same-origin', headers:xhrHeaders()})
        .then(function(r){ return r.text(); }).then(function(t){
            var tmp = document.createElement('div'); tmp.innerHTML = t;
            var fresh = tmp.querySelector('.va-mob.proto-app[data-model]');
            var cur = document.querySelector(wrapSel(child, ip));
            if (fresh && cur && cur.parentNode) {
                // Carry an open picker over to the fresh list (multi-add).
                var pop = document.getElementById('gc-cl-pop');
                if (pop && cur.contains(pop)) { placePop(fresh, pop); }
                cur.parentNode.replaceChild(fresh, cur);
                decorate(fresh);
                try { document.dispatchEvent(new CustomEvent('gc:list-refreshed', {detail:{model:child, parent:PARENT, ip:ip}})); } catch(_){}
            }
        }).catch(function(){});
    }

    // Inside the wrapper (.va-mob is absolutely positioned over its
    // container), right under the list header.
    function placePop(cw, pop){
        var head = cw.querySelector('.va-mob-header');
        if (head) { head.insertAdjacentElement('afterend', pop); } else { cw.insertAdjacentElement('afterbegin', pop); }
    }
    function closePop(){ var p = document.getElementById('gc-cl-pop'); if (p && p.parentNode) { p.parentNode.removeChild(p); } }

    function openPicker(cw, childKey){
        closePop();
        var c = CFG[childKey] || {}, ip = cw.getAttribute('data-ip') || '', added = {};
        var pop = document.createElement('div');
        pop.id = 'gc-cl-pop'; pop.className = 'gc-cl-pop';
        pop.innerHTML =
              '<div class="gc-cl-head"><span class="gc-cl-title">' + esc(c.addTitle || 'Link existing') + '</span>'
            + '<button type="button" class="gc-cl-done">' + esc(c.doneLabel || 'Done') + '</button></div>'
            + '<div class="gc-cl-search"><i class="ri-search-line"></i>'
            + '<input type="search" class="gc-cl-input" autocomplete="off" placeholder="' + esc(c.searchPlaceholder || 'Search…') + '"></div>'
            + '<div class="gc-cl-results" role="listbox"></div>';
        placePop(cw, pop);
        var input = pop.querySelector('.gc-cl-input'), res = pop.querySelector('.gc-cl-results');
        pop.querySelector('.gc-cl-done').addEventListener('click', closePop);
        pop.addEventListener('keydown', function(e){ if (e.key === 'Escape') { e.stopPropagation(); closePop(); } });

        function link(r, el){
            if (el.classList.contains('is-added') || el.classList.contains('is-busy')) { return; }
            var go = function(){
                el.classList.add('is-busy');
                post('childLinkSave', {child:childKey, i:JSON.stringify(r.id), ip:ip}).then(function(j){
                    el.classList.remove('is-busy');
                    if (j && j.status === 'success') {
                        added[JSON.stringify(r.id)] = 1;
                        el.classList.remove('is-linked'); el.classList.add('is-added');
                        el.querySelector('.gc-cl-state').textContent = '\u2713 ' + (c.addedLabel || 'Added');
                        toast(c.addedToast || 'Added');
                        refresh(childKey, ip);
                        try { input.focus(); input.select(); } catch(_){}
                    } else {
                        fail((j && j.message) || 'Could not add');
                    }
                }).catch(function(){ el.classList.remove('is-busy'); });
            };
            if (r.linked) {
                ask(c.reassignConfirm || 'Already assigned elsewhere — reassign it here?').then(function(ok){ if (ok) { go(); } });
            } else { go(); }
        }
        function render(rows){
            res.innerHTML = '';
            if (!rows.length) {
                res.innerHTML = '<div class="gc-cl-empty">' + esc(c.empty || 'No match') + '</div>';
                return;
            }
            rows.forEach(function(r){
                var cols = r.cols || [r.label], name = cols[0] || r.label || '',
                    meta = cols.slice(1).filter(function(v){ return v; }).join(' · '),
                    isAdded = !!added[JSON.stringify(r.id)];
                var el = document.createElement('div');
                el.className = 'gc-cl-row' + (isAdded ? ' is-added' : (r.linked ? ' is-linked' : ''));
                el.setAttribute('role', 'option'); el.tabIndex = 0;
                el.innerHTML = '<span class="gc-cl-av">' + esc((name.trim().charAt(0) || '?').toUpperCase()) + '</span>'
                    + '<span class="gc-cl-body"><span class="gc-cl-name">' + esc(name) + '</span>'
                    + (meta ? '<span class="gc-cl-meta">' + esc(meta) + '</span>' : '') + '</span>'
                    + '<span class="gc-cl-state">' + esc(isAdded ? '\u2713 ' + (c.addedLabel || 'Added')
                        : (r.linked ? (c.linkedNote || 'already assigned') : (c.addLabel || 'Add'))) + '</span>';
                el.addEventListener('click', function(){ link(r, el); });
                el.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); link(r, el); } });
                res.appendChild(el);
            });
        }
        var tId = null, seq = 0;
        input.addEventListener('input', function(){
            clearTimeout(tId);
            var v = input.value.trim();
            tId = setTimeout(function(){
                var my = ++seq;
                if (!v) { res.innerHTML = ''; return; }
                fetch(_SITE_URL + PARENT + '/childLinkSearch?child=' + encodeURIComponent(childKey)
                        + '&term=' + encodeURIComponent(v) + '&ip=' + encodeURIComponent(ip),
                    {credentials:'same-origin', headers:xhrHeaders()})
                .then(function(r){ return r.json(); }).then(function(j){
                    if (my === seq) { render((j && j.rows) || []); }
                }).catch(function(){});
            }, 250);
        });
        try { input.focus(); } catch(_){}
    }

    function unlink(childKey, ip, pk, after){
        var c = CFG[childKey] || {};
        ask(c.removeConfirm || 'Remove this entry from the list? The record itself is not deleted.',
            {confirmLabel: c.removeLabel || 'Remove', danger: true})
        .then(function(ok){
            if (!ok) { return; }
            post('childUnlink', {child:childKey, i:pkParam(pk), ip:ip}).then(function(j){
                if (j && j.status === 'success') {
                    toast(c.removedToast || 'Removed');
                    if (after) { after(); }
                    refresh(childKey, ip);
                } else {
                    fail((j && j.message) || 'Could not remove');
                }
            }).catch(function(){});
        });
    }

    // The linked list a stacked edit drawer was opened from (screen just below it).
    function listUnder(scr, childKey){
        var prev = scr.previousElementSibling;
        while (prev && !prev.classList.contains('proto-screen')) { prev = prev.previousElementSibling; }
        return prev ? prev.querySelector(wrapSel(childKey)) : null;
    }

    window.addEventListener('click', function(e){
        for (var childKey in CFG) {
            // Add: legacy #add{Child} AND the list header "+ New" (.add-btn).
            var add = e.target.closest('#add' + childKey + ', .add-btn');
            if (add) {
                var cw = add.closest('.va-mob.proto-app[data-model]');
                if (!cw || cw.getAttribute('data-model') !== childKey) { continue; }
                if (cw.getAttribute('data-parent') !== PARENT) { return; }
                e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
                openPicker(cw, childKey); return;
            }
            // "+" on the parent form's child tab: open the list, then the picker.
            var tabAdd = e.target.closest("[j='childadd_" + PARENT + "'][p='" + childKey + "']");
            if (tabAdd) {
                var scr0 = tabAdd.closest('.proto-screen') || document;
                var tab = scr0.querySelector("[j='conglet_" + PARENT + "'][p='" + childKey + "']");
                if (!tab) { return; }
                e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
                tab.click();
                var n = 0;
                (function poll(){
                    var all = document.querySelectorAll(wrapSel(childKey)), w = all.length ? all[all.length - 1] : null;
                    if (w) { openPicker(w, childKey); } else if (++n < 30) { setTimeout(poll, 200); }
                })();
                return;
            }
            // Row Remove (pk on the link's i=, else on the row's rid=).
            var del = e.target.closest(".ac-delete-link, [j='delete" + childKey + "']");
            if (del) {
                var cw2 = del.closest('.va-mob.proto-app[data-model]');
                if (cw2 && cw2.getAttribute('data-model') === childKey && cw2.getAttribute('data-parent') === PARENT) {
                    e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
                    var rowEl = del.closest('[rid]'), cellI = (del.closest('tr') || del).querySelector('[i]');
                    unlink(childKey, cw2.getAttribute('data-ip') || '',
                        del.getAttribute('i') || (rowEl ? rowEl.getAttribute('rid') : '') || (cellI ? cellI.getAttribute('i') : ''));
                    return;
                }
            }
            // Edit-drawer footer Delete of a member opened from a linked list:
            // remove from the list instead of deleting the record.
            var fdel = e.target.closest(".sw-delete, [j='delete']");
            if (fdel) {
                var scr = fdel.closest('.proto-screen');
                if (!scr || scr.getAttribute('data-model') !== childKey) { continue; }
                var under = listUnder(scr, childKey);
                if (!under) { continue; }
                e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
                unlink(childKey, under.getAttribute('data-ip') || '', fdel.getAttribute('rid') || scr.getAttribute('data-pk'), function(){
                    var back = scr.querySelector('[data-screen-back], .nav-back, .form-nav .nav-btn');
                    if (back) { back.click(); }
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
