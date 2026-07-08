<?php

namespace ApiGoat\Domains\DateCascade;

use ApiGoat\Sessions\AuthySession;

/**
 * Delete-cascade for date-linked sibling records (the `set_date_cascade_delete`
 * GoatCheese behavior). When a "trigger" row is deleted (e.g. a time_line), its
 * declared sibling tables (e.g. transportation) that share a set of match
 * columns — typically id_project + id_authy + date — can be offered for
 * deletion too.
 *
 * Two entry points, both driven by the emitted $cfg
 * (['siblings'=>[], 'match'=>[], 'only_if_last'=>bool, 'label'=>string]):
 *   - peek():    read-only; tells the UI whether to prompt + how many siblings.
 *   - cascade(): deletes the in-scope siblings after the trigger row is gone.
 *
 * Security: every query is scoped exactly like AuthySession::loadPkScoped
 * (tenant hard-partition + Owner/Group row scope for the model's delete right).
 * A non-root caller can only ever cascade-delete rows they could delete
 * directly, and only within their own tenant. only_if_last is ALWAYS
 * re-checked server-side in cascade() — the client's intent flag is never
 * trusted to bypass the "don't strand shared siblings" guard.
 */
class DateCascadeDelete
{
    /**
     * Snake_case -> CamelCase (id_project -> IdProject, date -> Date), while
     * leaving an already-class-style name intact (TimeLine stays TimeLine — do
     * NOT lowercase it, that would collapse the internal word boundary).
     */
    private static function camel(string $s): string
    {
        if (strpos($s, '_') === false) {
            return ucfirst($s);
        }
        return str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($s))));
    }

    /** 'transportation' | 'Transportation' -> \App\TransportationQuery */
    private static function queryClass(string $modelOrTable): string
    {
        return '\\App\\' . self::camel($modelOrTable) . 'Query';
    }

    /** Short model class name of a Propel object, e.g. 'TimeLine'. */
    private static function modelOf($obj): string
    {
        return (new \ReflectionClass($obj))->getShortName();
    }

    /**
     * The client-side onReadyJs interceptor for a trigger model's delete links.
     * A capture-phase document listener (bound once) that pre-empts the template
     * screens.js bubble-phase delete handler: it shows the normal confirm, peeks
     * the server for eligible same-day siblings, and when eligible asks the
     * 2-way "also delete the <label>?" prompt before POSTing the delete with
     * cascade_date_siblings=0|1. Kept here (not in the emitter) so the JS is a
     * single, single-escaped PHP string that both the main-list and child-list
     * code paths emit by CALL — never touches the template screens.js.
     */
    public static function interceptorScript(string $model): string
    {
        $js = <<<'JS'
(function(){
    if (window.__gcDcd_%MODEL%) { return; }
    window.__gcDcd_%MODEL% = 1;
    var MODEL = '%MODEL%';
    // Bind on window (capture): the template screens.js delete handler is a
    // capture listener on document, registered at page load — i.e. BEFORE this
    // one. A window-capture listener is outer in the capture path, so it fires
    // first and can stop the event before it reaches document (a document
    // capture listener could not pre-empt screens.js, which registered earlier).
    window.addEventListener('click', function(e){
        var link = e.target.closest("[j='delete%MODEL%']");
        if (!link) { return; }
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        var pkAttr = link.getAttribute('i') || '';
        var container = link.closest('[data-model]');
        var ui = container ? (container.getAttribute('data-ui') || '') : '';
        var row = link.closest('.va-mob-row, [rid], tr');
        function del(cascade){
            var body = 'ui=' + encodeURIComponent(ui) + '&cascade_date_siblings=' + (cascade ? '1' : '0');
            fetch(_SITE_URL + MODEL + '/delete/' + encodeURIComponent(pkAttr), {
                method:'POST', credentials:'same-origin',
                headers:{'X-Requested-With':'XMLHttpRequest','X-GC-Envelope':'1','Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body
            }).then(function(r){ return r.text(); }).then(function(txt){
                var refused=false, title='Cannot delete', msg='';
                try { var j=JSON.parse(txt); if (j && j.status && j.status!=='ok') { refused=true; if (j.messages && j.messages[0]) { title=j.messages[0].title||title; msg=j.messages[0].text||''; } } } catch(_){}
                if (!refused && /alertb\(/.test(txt) && !/sw_message\(/.test(txt)) { refused=true; msg='This record is in use.'; }
                if (refused) { if (window.gcScreens && gcScreens.alert) { gcScreens.alert(title, msg); } return; }
                if (row && row.parentNode) { row.parentNode.removeChild(row); }
                if (window.gcScreens && gcScreens.toast) { gcScreens.toast('Deleted'); }
            });
        }
        var cmsg = 'Delete this record? This cannot be undone.';
        var first = (window.gcScreens && gcScreens.confirm) ? gcScreens.confirm(cmsg,{confirmLabel:'Delete',danger:true}) : Promise.resolve(window.confirm(cmsg));
        Promise.resolve(first).then(function(ok){
            if (!ok) { return; }
            fetch(_SITE_URL + MODEL + '/dateCascadePeek?i=' + encodeURIComponent(pkAttr), {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}})
                .then(function(r){ return r.json(); }).then(function(pk){
                    if (pk && pk.eligible && pk.count > 0) {
                        var q = 'Also delete the ' + (pk.label || 'related') + ' for that day?';
                        Promise.resolve(gcScreens.confirm(q,{confirmLabel:'Delete both',danger:true})).then(function(also){ del(!!also); });
                    } else { del(false); }
                }).catch(function(){ del(false); });
        });
    }, true);
})();
JS;
        return str_replace('%MODEL%', $model, $js);
    }

    /**
     * Read the match-column values off the trigger object (via generated
     * getters). Returns null if ANY match value is null/empty — an ambiguous
     * key must never fan out to a broad sibling match.
     *
     * @return array<string,mixed>|null  snake_col => value
     */
    private static function matchValues($obj, array $matchCols): ?array
    {
        $vals = [];
        foreach ($matchCols as $col) {
            $v = $obj->{'get' . self::camel($col)}();
            if ($v === null || $v === '') {
                return null;
            }
            $vals[$col] = $v;
        }
        return $vals;
    }

    /** A match-column-filtered, RBAC/tenant-scoped query for $model. */
    private static function scopedQuery(string $model, array $matchCols, array $vals, AuthySession $session)
    {
        $qc = self::queryClass($model);
        $q  = $qc::create();
        foreach ($matchCols as $col) {
            $q->{'filterBy' . self::camel($col)}($vals[$col]);
        }
        if (! $session->isRoot()) {
            if ($session->get('id_tenant') && method_exists($q, 'filterByIdTenant')) {
                $q->filterByIdTenant($session->get('id_tenant'));
            }
            $session->applyOwnerGroupScope($q, $session->hasRights($model, 'd'));
        }
        return $q;
    }

    /**
     * @param string $model camelCase model class of the trigger (e.g. 'TimeLine')
     * @param mixed  $pk    trigger primary key
     * @param array  $cfg   behavior config
     * @return array{eligible: bool, count: int, label: string}
     */
    public static function peek(string $model, $pk, array $cfg, AuthySession $session): array
    {
        $label = (string) ($cfg['label'] ?? 'related');
        $out   = ['eligible' => false, 'count' => 0, 'label' => $label];
        $match = (array) ($cfg['match'] ?? []);

        // Scoped load — null when the caller may not delete this row.
        $obj = $session->loadPkScoped(self::queryClass($model), $pk, $model, 'd');
        if ($obj === null) {
            return $out;
        }

        $vals = self::matchValues($obj, $match);
        if ($vals === null) {
            return $out;
        }

        // only_if_last: the trigger row still exists here, so a total of exactly
        // 1 means it's the only one with this key.
        if (! empty($cfg['only_if_last'])) {
            if (self::scopedQuery($model, $match, $vals, $session)->count() > 1) {
                return $out;
            }
        }

        $count = 0;
        foreach ((array) ($cfg['siblings'] ?? []) as $sib) {
            if (! $session->isRoot() && ! $session->hasRights($sib, 'd')) {
                continue; // caller can't delete this sibling type — don't offer it
            }
            $count += self::scopedQuery($sib, $match, $vals, $session)->count();
        }
        $out['count']    = $count;
        $out['eligible'] = $count > 0;
        return $out;
    }

    /**
     * Delete the in-scope sibling rows sharing the trigger's match key. Call
     * AFTER the trigger row's own delete(). Returns the number deleted.
     */
    public static function cascade($obj, array $cfg, AuthySession $session): int
    {
        $match = (array) ($cfg['match'] ?? []);
        $vals  = self::matchValues($obj, $match);
        if ($vals === null) {
            return 0;
        }

        // Server-side only_if_last re-check. The trigger row is already deleted,
        // so any remaining own-model row with this key means other entries still
        // depend on the siblings — abort rather than strand them.
        if (! empty($cfg['only_if_last'])) {
            $model = self::modelOf($obj);
            if (self::scopedQuery($model, $match, $vals, $session)->count() > 0) {
                return 0;
            }
        }

        $deleted = 0;
        foreach ((array) ($cfg['siblings'] ?? []) as $sib) {
            if (! $session->isRoot() && ! $session->hasRights($sib, 'd')) {
                continue;
            }
            foreach (self::scopedQuery($sib, $match, $vals, $session)->find() as $row) {
                $row->delete();
                $deleted++;
            }
        }
        return $deleted;
    }
}
