<?php

namespace ApiGoat\Ops\Dashboard;

/**
 * Styles for the shared Security + Performance dashboards (card/kpi/table/
 * pill shapes, "ops-" prefix). Theme CSS custom properties only, no
 * hardcoded colors; the var(...,#hex) fallbacks are the ones the original
 * apigoatacc Analytics styles used.
 */
final class Styles
{
    public static function css(): string
    {
        return '<style' . gcNonceAttr() . '>
.ops-dash{padding:16px;display:flex;flex-direction:column;gap:16px}
.ops-head{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.ops-head h2{margin:0 auto 0 0}
.ops-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
.ops-kpi,.ops-card{background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:12px}
.ops-kpi-l{font-size:12px;opacity:.7}.ops-kpi-v{font-size:26px;font-weight:600}
.ops-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}
.ops-card h3{margin:0 0 8px;font-size:14px}
.ops-t{width:100%;border-collapse:collapse;font-size:13px}.ops-t th{text-align:left;opacity:.6;font-weight:500}
.ops-t td,.ops-t th{padding:4px 6px}
.ops-empty{opacity:.6;text-align:center}
.ops-foot{font-size:11px;opacity:.6}
.ops-counts{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
.ops-report-body{margin-top:8px}
.ops-report-frame{display:block;width:100%;height:70vh;min-height:420px;margin-top:8px;border:0}
.ops-t code{white-space:pre-wrap;word-break:break-all;font-size:12px}
.ops-mini{max-width:100%;height:60px!important}
.ops-spark{display:block;width:100%!important;height:36px!important;margin-top:6px;color:var(--mint,#00d1b2)}
.ops-svc{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
.ops-tip{cursor:help;text-decoration:underline dotted;text-underline-offset:3px}
</style>';
    }
}
