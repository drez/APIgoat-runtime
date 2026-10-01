<?php

namespace ApiGoat\Ops\Dashboard;

/**
 * Skin for the controls on the stand-alone dashboards (Analytics, Security,
 * Performance filter bars, Finance's secondary links, the year-end page).
 * Their pages do not carry the home dashboard's `.dt-dashboard .dt-dash-head`
 * scope, so `.dt-head-btn` / bare inputs rendered as browser defaults.
 *
 * Mirrors Finance's period bar (.fin-apply / date input) on theme tokens only:
 *   .dash-btn            neutral pill        .dash-btn--primary  accent pill
 *   .dash-input          date / select / text input
 *   summary.dash-btn     a <details> toggle rendered as a neutral pill
 */
final class Controls
{
    public static function css(): string
    {
        return '<style' . gcNonceAttr() . '>
.dash-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:34px;padding:0 14px;border:1px solid var(--line);border-radius:7px;background:var(--surface);color:var(--text);font:inherit;font-size:13px;font-weight:500;line-height:1;text-decoration:none;white-space:nowrap;cursor:pointer;transition:background .12s,border-color .12s}
.dash-btn:hover{background:var(--row-hover);border-color:var(--line-hard);color:var(--ink)}
.dash-btn i{font-size:15px;line-height:1;color:inherit}
.dash-btn--primary{background:var(--mint);border-color:var(--mint);color:var(--on-accent);font-weight:600}
.dash-btn--primary:hover{background:var(--mint-600);border-color:var(--mint-600);color:var(--on-accent)}
.dash-btn:focus-visible,.dash-input:focus{outline:none;border-color:var(--mint);box-shadow:0 0 0 3px var(--mint-100)}
.dash-input{height:34px;padding:0 10px;border:1px solid var(--line);border-radius:7px;background:var(--surface);color:var(--text);font:inherit;font-size:13px}
select.dash-input{padding-right:28px}
summary.dash-btn{list-style:none;width:max-content}
summary.dash-btn::-webkit-details-marker{display:none}
</style>';
    }
}
