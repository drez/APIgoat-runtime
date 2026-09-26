<?php

namespace ApiGoat\Ops\Dashboard;

use ApiGoat\Ops\Config;

/**
 * Which site(s) a dashboard shows. Only the hub (with_ops_monitor hub: true)
 * has a choice: its ops_* tables hold every forwarding app's rows, keyed by
 * site_id (the ana_site id; 0 = the hub's own rows). Every other app only
 * ever holds its own site_id = 0 rows, so it gets no selector at all.
 *
 * The site list comes from the project seam (Extras::sites(), the hub's
 * ana_site rows). Config::selfSite() (env GC_OPS_SELF_SITE) names the
 * ana_site that IS the hub, so selecting it also covers the site_id = 0
 * rows; without it the hub lists itself as "This app" (value 0).
 */
final class Scope
{
    /** @var list<array{id:int, name:string, host:?string}> */
    private array $sites = [];

    private ?int $selected = null;

    /** @param array<string,mixed> $q the request's query params */
    public function __construct(array $q, ?bool $hub = null, ?array $sites = null)
    {
        if (!($hub ?? Config::isHub())) {
            return;
        }
        $this->sites = $sites ?? Extras::sites();
        if (Config::selfSite() === null) {
            \array_unshift($this->sites, ['id' => 0, 'name' => _('This app'), 'host' => null]);
        }
        $raw = $q['site'] ?? '';
        if (\is_string($raw) && \ctype_digit($raw) && $this->find((int) $raw) !== null) {
            $this->selected = (int) $raw;
        }
    }

    public function isHub(): bool
    {
        return $this->sites !== [];
    }

    /** The selected ana_site id, or null for "All sites". */
    public function selected(): ?int
    {
        return $this->selected;
    }

    /** The site_id list for Stats, or null for every site. @return ?list<int> */
    public function statsSites(): ?array
    {
        if ($this->selected === null) {
            return null;
        }

        return $this->selected === Config::selfSite() ? [0, $this->selected] : [$this->selected];
    }

    /**
     * The selected site when it is another app (its local-only panels —
     * logins, deny routes, OAuth clients, table sizes — live in that app's
     * own dashboard), else null.
     *
     * @return ?array{id:int, name:string, host:?string}
     */
    public function remote(): ?array
    {
        if ($this->selected === null || $this->selected === 0 || $this->selected === Config::selfSite()) {
            return null;
        }

        return $this->find($this->selected);
    }

    /** Show a Site column: the hub, with "All sites" selected. */
    public function showsSiteColumn(): bool
    {
        return $this->isHub() && $this->selected === null;
    }

    /** Display name for a row's site_id. */
    public function label(int $siteId): string
    {
        if ($siteId === 0) {
            $self = Config::selfSite();

            return $self !== null ? ($this->find($self)['name'] ?? _('This app')) : _('This app');
        }

        return $this->find($siteId)['name'] ?? '#' . $siteId;
    }

    /** `<select name="site">`, or '' when not the hub. */
    public function select(): string
    {
        if (!$this->isHub()) {
            return '';
        }
        $e = fn ($s) => \htmlspecialchars((string) $s, \ENT_QUOTES);
        $html = '<option value="">' . $e(_('All sites')) . '</option>';
        foreach ($this->sites as $s) {
            $sel = $this->selected === $s['id'] ? ' selected' : '';
            $html .= '<option value="' . (int) $s['id'] . "\"{$sel}>" . $e($s['name']) . '</option>';
        }

        return '<select class="dash-input" name="site">' . $html . '</select>';
    }

    /**
     * A card that points at the remote app's own dashboard, for the panels
     * the hub never receives.
     */
    public function remoteCard(string $title, string $route): string
    {
        $e = fn ($s) => \htmlspecialchars((string) $s, \ENT_QUOTES);
        $site = $this->remote();
        $host = $site['host'] ?? null;
        $msg = $e(\sprintf(_('%s is not sent to this dashboard; it stays in %s\'s own dashboard.'), $title, $site['name'] ?? ''));
        $link = $host !== null
            ? ' <a href="https://' . $e($host) . '/' . $e($route) . '" target="_blank" rel="noopener">' . $e(\sprintf(_('Open %s dashboard'), $site['name'])) . ' →</a>'
            : '';

        return '<div class="ops-card"><h3>' . $e($title) . '</h3><p class="ops-foot">' . $msg . $link . '</p></div>';
    }

    /** @return ?array{id:int, name:string, host:?string} */
    private function find(int $id): ?array
    {
        foreach ($this->sites as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }

        return null;
    }
}
