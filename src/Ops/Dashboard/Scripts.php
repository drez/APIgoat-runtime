<?php

namespace ApiGoat\Ops\Dashboard;

/**
 * Chart drawing for the shared dashboards, from the `data-series` attributes
 * the views write: Security's #ops-trend (failed / ok logins per day);
 * Performance's #perf-latency-trend (avg + p95 ms per bucket) and
 * #perf-server-trend (24h load1 / disk_pct), plus a sparkline in each
 * top KPI tile (canvas.ops-spark: data-ts / data-v / data-unit). Chart.js ships with the runtime
 * (assets/chart.umd.min.js, served by Page::chartJs at Ops/chart.js) so an
 * app needs no vendored copy of its own.
 *
 * Performance bucket keys are unix timestamps, labelled in the browser:
 * hourly buckets as local date + hour, daily buckets (Stats::latencyTrend()
 * uses UTC days past a 3-day range) as the UTC date.
 */
final class Scripts
{
    public static function security(): string
    {
        return self::chartTag()
            . '<script' . gcNonceAttr() . '>(function(){var c=document.getElementById("ops-trend");if(!c||!window.Chart)return;'
            . 'var s=JSON.parse(c.getAttribute("data-series")||"[]"),labels=s.map(function(r){return r.day});'
            . 'new Chart(c,{type:"line",data:{labels:labels,datasets:['
            . '{label:"Failed",data:s.map(function(r){return r.failed}),tension:.3},'
            . '{label:"OK",data:s.map(function(r){return r.ok}),tension:.3}'
            . ']},options:{responsive:true,interaction:{mode:"index",intersect:false},plugins:{legend:{position:"bottom"}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});})();</script>';
    }

    public static function performance(): string
    {
        return self::chartTag()
            . '<script' . gcNonceAttr() . '>(function(){
if(!window.Chart)return;
function fmtTs(ts,daily){
  var d=new Date(Number(ts)*1000);
  if(isNaN(d.getTime()))return String(ts);
  return daily
    ?d.toLocaleDateString(undefined,{timeZone:"UTC",year:"numeric",month:"short",day:"numeric"})
    :d.toLocaleString(undefined,{month:"short",day:"numeric",hour:"2-digit",minute:"2-digit"});
}
var t=document.getElementById("perf-latency-trend");
if(t){
  var s=JSON.parse(t.getAttribute("data-series")||"[]");
  var daily=s.length>1&&(s[1].hour-s[0].hour)>=86400&&s.every(function(r){return r.hour%86400===0});
  var labels=s.map(function(r){return fmtTs(r.hour,daily)});
  new Chart(t,{type:"line",data:{labels:labels,datasets:[
    {label:"Avg ms",data:s.map(function(r){return r.avg_ms}),tension:.3},
    {label:"p95 ms",data:s.map(function(r){return r.p95_ms}),tension:.3}
  ]},options:{responsive:true,interaction:{mode:"index",intersect:false},plugins:{legend:{position:"bottom"}},scales:{y:{beginAtZero:true}}}});
}
document.querySelectorAll("canvas.ops-spark").forEach(function(c){
  var ts=JSON.parse(c.getAttribute("data-ts")||"[]"),v=JSON.parse(c.getAttribute("data-v")||"[]"),u=c.getAttribute("data-unit")||"";
  var dly=ts.length>1&&(ts[1]-ts[0])>=86400&&ts.every(function(x){return x%86400===0});
  var col=getComputedStyle(c).color;
  new Chart(c,{type:"line",data:{labels:ts.map(function(x){return fmtTs(x,dly)}),datasets:[{data:v,borderColor:col,backgroundColor:col,borderWidth:2,pointRadius:0,pointHoverRadius:4,tension:.3,spanGaps:true}]},
    options:{responsive:true,maintainAspectRatio:false,animation:false,layout:{padding:3},interaction:{mode:"index",intersect:false},
      plugins:{legend:{display:false},tooltip:{displayColors:false,callbacks:{label:function(i){return i.formattedValue+u}}}},
      scales:{x:{display:false},y:{display:false,grace:"10%"}}}});
});
var sv=document.getElementById("perf-server-trend");
if(sv){
  var r=JSON.parse(sv.getAttribute("data-series")||"[]"),lbl=r.map(function(x){return fmtTs(x.created_at,false)});
  new Chart(sv,{type:"line",data:{labels:lbl,datasets:[
    {label:"Load (1m)",data:r.map(function(x){return x.load1}),tension:.3,pointRadius:0},
    {label:"Disk %",data:r.map(function(x){return x.disk_pct}),tension:.3,pointRadius:0}
  ]},options:{responsive:true,plugins:{legend:{position:"bottom"}},scales:{x:{display:false},y:{beginAtZero:true}}}});
}
})();</script>';
    }

    private static function chartTag(): string
    {
        $subDirUrl = defined('_SUB_DIR_URL') ? _SUB_DIR_URL : '';

        return '<script' . gcNonceAttr() . ' src="' . htmlspecialchars($subDirUrl . 'Ops/chart.js', ENT_QUOTES) . '"></script>';
    }
}
