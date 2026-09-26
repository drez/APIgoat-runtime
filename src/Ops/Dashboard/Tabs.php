<?php

namespace ApiGoat\Ops\Dashboard;

/**
 * Vertical tab list for a dashboard's drilldown cards (Finance, Analytics,
 * Performance, Security): the KPIs + trend chart stay on top, the detail
 * cards below show one at a time, picked from a left-hand tab list (a
 * horizontal strip under 720px). Cards are rendered server-side and only
 * hidden, so the no-JS page still shows the first one. The card's own
 * heading is hidden in a panel — the active tab already names it
 * (!important: FinanceStyles sets .fin-card-head under #dashFinance).
 *
 * The selected tab is kept per dashboard in sessionStorage so a filter
 * "Apply" (full page reload) lands back on the same card.
 */
final class Tabs
{
    /**
     * @param string $key    dashboard id (storage key + DOM id prefix), [a-z0-9-]
     * @param list<array{0: string, 1: string}> $panels [label, card html]
     */
    public static function render(string $key, array $panels): string
    {
        $key = preg_replace('/[^a-z0-9-]/', '', strtolower($key)) ?: 'dd';
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $tabs = '';
        $bodies = '';
        foreach (array_values($panels) as $i => [$label, $html]) {
            $id = "dd-$key-$i";
            $on = $i === 0;
            $tabs .= '<button type="button" role="tab" class="dd-tab' . ($on ? ' is-active' : '') . '" id="' . $id . '-tab"'
                . ' aria-controls="' . $id . '" aria-selected="' . ($on ? 'true' : 'false') . '" tabindex="' . ($on ? '0' : '-1') . '">'
                . $e($label) . '</button>';
            $bodies .= '<div role="tabpanel" class="dd-panel" id="' . $id . '" aria-labelledby="' . $id . '-tab"' . ($on ? '' : ' hidden') . '>'
                . $html . '</div>';
        }

        return self::css()
            . '<div class="dd-tabs" data-dd-key="' . $e($key) . '">'
            . '<div class="dd-list" role="tablist" aria-orientation="vertical">' . $tabs . '</div>'
            . '<div class="dd-panels">' . $bodies . '</div>'
            . '</div>'
            . self::js();
    }

    private static function css(): string
    {
        return '<style' . gcNonceAttr() . '>
.dd-tabs{display:grid;grid-template-columns:200px minmax(0,1fr);gap:16px;align-items:start}
.dd-list{display:flex;flex-direction:column;gap:2px;position:sticky;top:12px}
.dd-tab{appearance:none;background:transparent;border:0;border-left:2px solid transparent;border-radius:0 6px 6px 0;padding:8px 12px;text-align:left;font:inherit;font-size:13px;font-weight:600;color:var(--text-mute);cursor:pointer}
.dd-tab:hover{color:var(--ink);background:var(--row-hover)}
.dd-tab.is-active{color:var(--ink);border-left-color:var(--mint);background:var(--row-active)}
.dd-tab:focus-visible{outline:2px solid var(--mint);outline-offset:-2px}
.dd-panel[hidden]{display:none}
.dd-panel>*{margin:0}
.dd-panel>.ana-card>h3:first-child,.dd-panel>.ops-card>h3:first-child,.dd-panel>.fin-card>.fin-card-head{display:none!important}
@media (max-width:719px){
.dd-tabs{grid-template-columns:minmax(0,1fr)}
.dd-list{flex-direction:row;overflow-x:auto;position:static;border-bottom:1px solid var(--line);scrollbar-width:none}
.dd-list::-webkit-scrollbar{display:none}
.dd-tab{border-left:0;border-bottom:2px solid transparent;border-radius:0;white-space:nowrap}
.dd-tab.is-active{border-bottom-color:var(--mint);background:transparent}
}
</style>';
    }

    private static function js(): string
    {
        return '<script' . gcNonceAttr() . '>(function(){
if(window.__gcDdTabs)return;window.__gcDdTabs=1;
function sel(tab,focus){var root=tab.closest(".dd-tabs");if(!root)return;
root.querySelectorAll(".dd-tab").forEach(function(t){var on=t===tab;t.classList.toggle("is-active",on);t.setAttribute("aria-selected",on?"true":"false");t.tabIndex=on?0:-1;
var p=document.getElementById(t.getAttribute("aria-controls"));if(p)p.hidden=!on;});
if(focus)tab.focus();
try{sessionStorage.setItem("gcDd:"+root.dataset.ddKey,tab.id);}catch(e){}}
document.addEventListener("click",function(ev){var t=ev.target.closest&&ev.target.closest(".dd-tab");if(t)sel(t,false);});
document.addEventListener("keydown",function(ev){var t=ev.target.closest&&ev.target.closest(".dd-tab");if(!t)return;
var d={ArrowDown:1,ArrowRight:1,ArrowUp:-1,ArrowLeft:-1}[ev.key];var all=[].slice.call(t.parentNode.querySelectorAll(".dd-tab"));
var i=all.indexOf(t);if(d)i=(i+d+all.length)%all.length;else if(ev.key==="Home")i=0;else if(ev.key==="End")i=all.length-1;else return;
ev.preventDefault();sel(all[i],true);});
function restore(){document.querySelectorAll(".dd-tabs").forEach(function(root){var id=null;
try{id=sessionStorage.getItem("gcDd:"+root.dataset.ddKey);}catch(e){}
var t=id&&document.getElementById(id);if(t&&root.contains(t))sel(t,false);});}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",restore);else restore();
})();</script>';
    }
}
