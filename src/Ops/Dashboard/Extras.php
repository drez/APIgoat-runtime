<?php

namespace ApiGoat\Ops\Dashboard;

/**
 * The optional project seam for the shared dashboards: a project class
 * `App\Domains\Ops\DashboardExtras` with any of these static methods —
 *
 *   sites(): list<array{id:int, name:string, host:?string}>
 *       hub only: the sites that forward to it (the ana_site rows).
 *   topNav(string $dashboard): string
 *       HTML above the dashboard ('security' | 'performance'), e.g. the
 *       project's own dashboard tab strip.
 *   performanceTabs(?int $site, int $from, int $to): list<array{0:string, 1:string}>
 *       extra [label, card html] drilldown tabs (apigoatacc: Web vitals).
 *
 * A project without the class, or without a method, gets the default ([] /
 * ''). The seam's output is trusted HTML: it is project code.
 */
final class Extras
{
    public const CLASS_NAME = '\\App\\Domains\\Ops\\DashboardExtras';

    /** @return list<array{id:int, name:string, host:?string}> */
    public static function sites(): array
    {
        $rows = self::call('sites', []) ?? [];
        $out = [];
        foreach ((array) $rows as $r) {
            if (isset($r['id'], $r['name'])) {
                $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'host' => isset($r['host']) && $r['host'] !== '' ? (string) $r['host'] : null];
            }
        }

        return $out;
    }

    public static function topNav(string $dashboard): string
    {
        return (string) (self::call('topNav', [$dashboard]) ?? '');
    }

    /** @return list<array{0:string, 1:string}> */
    public static function performanceTabs(?int $site, int $from, int $to): array
    {
        return \array_values((array) (self::call('performanceTabs', [$site, $from, $to]) ?? []));
    }

    private static function call(string $method, array $args)
    {
        $cls = self::CLASS_NAME;
        if (!\class_exists($cls) || !\method_exists($cls, $method)) {
            return null;
        }

        return $cls::$method(...$args);
    }
}
