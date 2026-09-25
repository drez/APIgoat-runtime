<?php

namespace ApiGoat\Ops\Server;

/**
 * A source of server health data for the Security & Performance dashboards'
 * server panel (ops_server_snap, emitted by with_ops_monitor). Exactly one
 * concrete implementation is picked per project by Factory::make(), based on
 * Config::get('server_source').
 *
 * R4 (controller ruling, Task 6): prod (ISPConfig) runs the app as a jailed
 * web user with no MySQL/root access, so the only source ever implemented is
 * SnapshotFileSource, fed by a root cron (RT/bin/ops-collect.sh) that writes
 * a JSON file inside the site's own open_basedir. An IspconfigSource reading
 * dbispconfig.monitor_data directly was considered and dropped — the app
 * user cannot reach that database.
 */
interface Source
{
    /**
     * The current server snapshot, normalized to:
     *
     *   ['load1'=>float, 'mem_pct'=>float, 'disk_pct'=>float,
     *    'services'=>array<string,bool>, 'f2b_banned'=>int,
     *    'f2b'=>array<string,int>,
     *    'auth'=>['ssh_failed'=>int,'ssh_accepted'=>int,'window_h'=>int],
     *    'at'=>int]
     *
     * or null when no data is available at all (missing/unreadable/invalid
     * file, source misconfigured, ...). A snapshot whose `at` is old is
     * still returned — staleness is a display concern for the caller, not a
     * reason to withhold the data. Must never throw.
     *
     * @return array{load1:float,mem_pct:float,disk_pct:float,services:array<string,bool>,f2b_banned:int,f2b:array<string,int>,auth:array{ssh_failed:int,ssh_accepted:int,window_h:int},at:int}|null
     */
    public function snapshot(): ?array;
}
