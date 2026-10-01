<?php

namespace ApiGoat\Ops\Dashboard;

/**
 * The top-row KPI tile every admin dashboard shares (Security, Performance,
 * and a project's own, e.g. apigoatacc's Analytics): a label, the value and
 * a sparkline of the value's history over the selected range. A tile with
 * no history (a right-now count) keeps the same height with no line, so a
 * row of tiles always lines up.
 *
 * css() / js() are self-contained: a page includes them once, and js()
 * draws every canvas.ops-spark with Chart.js, loaded by the page itself
 * before it (Scripts::chartTag or the project's own copy).
 */
final class Kpi
{
    /**
     * @param ?array{0:list<int|string>, 1:list<int|float|null>, 2?:string} $spark
     *        [x labels (unix timestamps or ready-made strings like a date), values, unit suffix]
     * @param ?string $level   Level::of() result ('normal' amber / 'high' red):
     *                          colors the number and the tile's top edge — the
     *                          value keeps its full size (a pill shrank problem
     *                          numbers, so the worst ones read the smallest)
     * @param ?string $caption  small line under the value ("1 overdue", "across 4 clients")
     * @param ?string $tone     'good' for good-news values (collected, net profit)
     * @param ?string $href     makes the caption a link (e.g. "1 overdue" → the overdue bills)
     */
    public static function tile(string $label, string|int|float|null $value, ?array $spark = null, ?string $level = null, ?string $caption = null, ?string $tone = null, ?string $href = null): string
    {
        $e = static fn ($s) => \htmlspecialchars((string) $s, \ENT_QUOTES);
        $line = $spark !== null && \count($spark[1]) > 1
            ? '<canvas class="ops-spark" height="36" role="img" aria-label="' . $e(\sprintf(_('%s trend'), $label)) . '"'
                . ' data-ts="' . $e(\json_encode(\array_values($spark[0]))) . '" data-v="' . $e(\json_encode(\array_values($spark[1]))) . '"'
                . ' data-unit="' . $e($spark[2] ?? '') . '"></canvas>'
            : ($spark !== null ? '<div class="ops-spark ops-spark--none"></div>' : ''); // an empty series keeps a sparkline row aligned
        $mod = \in_array($level, ['normal', 'high'], true) ? ' ops-kpi--' . $level : ($tone === 'good' ? ' ops-kpi--good' : '');

        return '<div class="ops-kpi' . $mod . '"><div class="ops-kpi-l">' . $e($label) . '</div>'
            . '<div class="ops-kpi-v">' . ($value === null || $value === '' ? '—' : $e($value)) . '</div>'
            . ($caption !== null && $caption !== ''
                ? '<div class="ops-kpi-c">' . ($href !== null && $href !== '' ? '<a href="' . $e($href) . '">' . $e($caption) . '</a>' : $e($caption)) . '</div>'
                : '')
            . $line . '</div>';
    }

    /**
     * Every calendar day from $from to $to (Y-m-d, server time) — the x
     * axis for day-bucketed series, so a quiet day is a 0, not a gap.
     *
     * @return list<string>
     */
    public static function days(int $from, int $to): array
    {
        $out = [];
        for ($d = \strtotime(\date('Y-m-d', $from)); $d <= $to && \count($out) < 3700; $d = \strtotime('+1 day', $d)) {
            $out[] = \date('Y-m-d', $d);
        }

        return $out;
    }

    public static function css(): string
    {
        return '<style' . gcNonceAttr() . '>
.ops-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
.ops-kpi{background:var(--surface,#fff);border:1px solid var(--line,#e3e8ee);border-top:3px solid transparent;border-radius:10px;padding:12px 14px;min-width:0}
.ops-kpi-l{font-size:12px;font-weight:500;color:var(--text-mute,#627286)}
.ops-kpi-v{font-size:26px;font-weight:650;line-height:1.2;color:var(--ink,#0a2540);font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ops-kpi-c{font-size:12px;color:var(--text-mute,#627286);margin-top:2px}.ops-kpi-c a{color:inherit;text-decoration:none}.ops-kpi-c a:hover{text-decoration:underline}
.ops-kpi--high .ops-kpi-c{color:var(--danger,#df1b41)}.ops-kpi--normal .ops-kpi-c{color:var(--warning-700,#b45309)}
.ops-kpi--normal{border-top-color:var(--warning,#f59e0b)}.ops-kpi--normal .ops-kpi-v{color:var(--warning-700,#b45309)}
.ops-kpi--high{border-top-color:var(--danger,#df1b41)}.ops-kpi--high .ops-kpi-v{color:var(--danger,#df1b41)}
.ops-kpi--good .ops-kpi-v{color:var(--mint-700,#009b82)}
.ops-spark{display:block;width:100%!important;height:36px!important;margin-top:6px;color:var(--mint,#00d1b2)}
</style>';
    }

    public static function js(): string
    {
        return '<script' . gcNonceAttr() . '>(function(){
function draw(){
  if(!window.Chart)return;
  function lbl(x,daily){
    if(typeof x!=="number")return String(x);
    var d=new Date(x*1000);
    return daily?d.toLocaleDateString(undefined,{timeZone:"UTC",year:"numeric",month:"short",day:"numeric"})
      :d.toLocaleString(undefined,{month:"short",day:"numeric",hour:"2-digit",minute:"2-digit"});
  }
  document.querySelectorAll("canvas.ops-spark").forEach(function(c){
    if(c.dataset.drawn)return;c.dataset.drawn="1";
    var ts=JSON.parse(c.getAttribute("data-ts")||"[]"),v=JSON.parse(c.getAttribute("data-v")||"[]"),u=c.getAttribute("data-unit")||"";
    var daily=ts.length>1&&typeof ts[0]==="number"&&(ts[1]-ts[0])>=86400&&ts.every(function(x){return x%86400===0});
    var col=getComputedStyle(c).color;
    new Chart(c,{type:"line",data:{labels:ts.map(function(x){return lbl(x,daily)}),datasets:[{data:v,borderColor:col,backgroundColor:col,borderWidth:2,pointRadius:0,pointHoverRadius:4,tension:.3,spanGaps:true}]},
      options:{responsive:true,maintainAspectRatio:false,animation:false,layout:{padding:3},interaction:{mode:"index",intersect:false},
        plugins:{legend:{display:false},tooltip:{displayColors:false,callbacks:{label:function(i){return i.formattedValue+u}}}},
        scales:{x:{display:false},y:{display:false,grace:"10%"}}}});
  });
}
if(window.Chart)draw();else window.addEventListener("load",draw);
})();</script>';
    }
}
