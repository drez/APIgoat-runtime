<?php

namespace ApiGoat\Views;

use Psr\Http\Message\ServerRequestInterface as Request;
use ApiGoat\Utility\RowCount;
//use ApiGoat\Views\View;

class WelcomeView
{

    public $request;
    public $args;
    public $model_name;




    /**
     * Constructor
     *
     * @param Request $request
     * @param Array $args
     */
    function __construct(Request $request, array $args)
    {
        $this->request = $request;
        $this->args = $args;
        $this->model_name = 'WelcomeView';
        $this->virtualClassName = 'WelcomeView';

    }

    // Tabbed dashboard (2026-06-27 redesign):
    // .proto-screen.welcome-screen
    //   .welcome-greeting (h1 + .welcome-date)
    //   .welcome-stack
    //     .sw-drawer.proto-form.welcome-tabbed-card
    //       .welcome-tabnav  : [ Overview ] [ Settings ] [ API security ]
    //       .welcome-tab-panes
    //         #welTab_overview  — status pill + stat-card grid + quick links
    //         #welTab_settings  — Config categories, stacked sub-sections
    //         #welTab_apisec    — API doc links (only when $hasAPI)
    //
    // Overview is data-agnostic: the stat cards come from the project's own
    // menu map (config/menus.php) gated by the same ACL check the sidebar uses
    // (group Admin or AuthySession::hasMenu), counted via RowCount::forModel
    // (per-request memo, tenant-scoped — identical numbers to the sidebar
    // chips). Config editing is unchanged (same ag_save='Config' contract),
    // only relocated into the Settings tab.
    public function dashboard()
    {

        $Configs = \App\ConfigQuery::create()
            ->orderBy('Category', 'ASC')
            ->find();

        // --- Greeting block ---
        $username = '';
        if (isset($_SESSION[_AUTH_VAR]) && method_exists($_SESSION[_AUTH_VAR], 'getUsername')) {
            $username = $_SESSION[_AUTH_VAR]->getUsername();
        }
        if (empty($username)) {
            $username = _('there');
        }
        if (class_exists('IntlDateFormatter')) {
            $dateLong = (new \IntlDateFormatter('en_US', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE))
                ->format(new \DateTimeImmutable('now'));
        } else {
            $dateLong = (new \DateTimeImmutable('now'))->format('l, F j, Y');
        }

        $greeting = div(
            h1("Welcome, " . htmlspecialchars($username) . " 👋")
                . div(htmlspecialchars($dateLong), '', "class='welcome-date'"),
            '',
            "class='welcome-greeting'"
        );

        // --- Collect Config rows by category, isolate app_status, detect API ---
        $categoryBuckets = [];
        $appStatusRow    = null;
        $appStatusId     = null;
        $hasAPI          = false;

        foreach ($Configs as $Config) {
            if ($Config->getConfig() === 'app_status') {
                $appStatusRow = $Config;
                $appStatusId  = $Config->getIdConfig();
                continue;
            }
            if ($Config->getConfig() === 'api_ips') {
                $hasAPI = true;
            }
            $categoryBuckets[$Config->getCategory()][] = $Config;
        }

        $slug = function ($s) {
            return 'welTab_' . preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $s);
        };

        // =========================================================
        // Overview pane — app-status pill + stat cards + quick links
        // =========================================================
        $overview = '';

        // App-status pill: wraps the SAME checkbox + hidden Value/IdConfig +
        // ag_save handler the old "App state" card used, so the save contract
        // (POST Config/update/{id}, ui:'tabsContain') is unchanged. Only the
        // surrounding markup/styling moved.
        if ($appStatusRow) {
            $isProd     = ($appStatusRow->getValue() === 'prod');
            $checked    = $isProd ? 'checked=true' : '';
            $stateCls   = $isProd ? 'is-prod' : 'is-dev';
            $stateLabel = $isProd ? _('Production') : _('Development');
            $overview .= div(
                form(
                    "<span class='welcome-status-dot' aria-hidden='true'></span>"
                        . "<span class='welcome-status-text'>" . $stateLabel . "</span>"
                        . input('checkbox', 'config', 'app_status', $checked . " config='app_status' ag_save='Config'")
                        . label('', "for='config' class='welcome-status-toggle' title='" . _('Toggle Development / Production') . "'")
                        . input('hidden', 'IdConfig', $appStatusId, "ag_save='Config'")
                        . input('hidden', 'Value', '', "ag_save='Config'"),
                    "id='form_app_status'"
                ),
                '', "class='welcome-status-pill " . $stateCls . "'"
            );
        }

        // Stat cards: accessible top entities from the menu map, deduped,
        // counted, capped. ACL + count source identical to the sidebar.
        $statCards = '';
        $menuFile  = _BASE_DIR . 'config/menus.php';
        if (is_file($menuFile)) {
            $menus = require $menuFile;
            if (is_array($menus)) {
                $seen  = [];
                $auth  = $_SESSION[_AUTH_VAR] ?? null;
                $isAdmin = $auth && method_exists($auth, 'get') && $auth->get('group') === 'Admin';
                foreach ($menus as $item) {
                    if (count($seen) >= 8) {
                        break;
                    }
                    $name  = $item['name'] ?? '';
                    $route = $item['route'] ?? null;
                    // Skip the hardcoded Settings entry (route '/') and blanks.
                    if ($name === '' || $route === '/' || isset($seen[$name])) {
                        continue;
                    }
                    // Same ACL gate as Menu::addUnder.
                    if (!$isAdmin && !($auth && method_exists($auth, 'hasMenu') && $auth->hasMenu($name))) {
                        continue;
                    }
                    $count = RowCount::forModel($name);
                    if ($count === null) {
                        continue; // not a real countable model
                    }
                    $seen[$name] = true;
                    $desc = $item['desc'] ?? $name;
                    $icon = !empty($item['icon'])
                        ? "<i class='" . htmlspecialchars($item['icon']) . "' aria-hidden='true'></i> "
                        : '';
                    $statCards .= "<a class='welcome-stat-card' href='" . _SITE_URL . htmlspecialchars($name) . "'>"
                        . "<span class='welcome-stat-count'>" . (int) $count . "</span>"
                        . "<span class='welcome-stat-label'>" . $icon . htmlspecialchars($desc) . "</span>"
                        . "</a>";
                }
            }
        }
        if ($statCards !== '') {
            $overview .= "<div class='welcome-stat-grid'>" . $statCards . "</div>";
        }

        // Quick links: a small static set (data-agnostic).
        $overview .= "<div class='welcome-quicklinks'>"
            . "<a class='welcome-quicklink' target='_doc' href='https://apigoat.com/docs/'>" . _('Documentation') . "</a>"
            . "<a class='welcome-quicklink' target='_doc' href='https://apigoat.com/docs/api/rest-api-basics/'>" . _('API basics') . "</a>"
            . "<a class='welcome-quicklink' href='" . _SITE_URL . "Account'>" . _('Account') . "</a>"
            . "</div>";

        // =========================================================
        // Settings pane — Config categories as stacked sub-sections
        // =========================================================
        $settings = '';
        foreach ($categoryBuckets as $category => $rows) {
            $groupRows = '';
            foreach ($rows as $Config) {
                $groupRows .= div(
                    form(
                        label($Config->getConfig())
                            . input('text', 'Value', htmlentities($Config->getValue()), "config='" . $Config->getConfig() . "' ag_save='Config'")
                            . input('hidden', 'IdConfig', $Config->getIdConfig(), "ag_save='Config'")
                            . div(htmlspecialchars($Config->getDescription() ?? ''), '', "class='explain'"),
                        "id='form_" . $Config->getConfig() . "'"
                    ),
                    '', "class='form-row'"
                );
            }
            $settings .= "<div class='welcome-settings-group'>"
                . "<div class='welcome-settings-grouphdr'>" . htmlspecialchars($category) . "</div>"
                . $groupRows
                . "</div>";
        }

        // =========================================================
        // API security pane (only when the project exposes the API)
        // =========================================================
        $apisec = '';
        if ($hasAPI) {
            $apisec = div(
                div(_("Before using the API, please make yourself familiar with:"))
                    . ul(
                        li(_("the ") . "<a target='_doc' href='https://apigoat.com/docs/your-app/api-acl/'>" . _("Rule Based Access List") . "</a>")
                            . li(_("the ") . "<a target='_doc' href='https://apigoat.com/docs/your-app/permissions/'>" . _("App Permissions") . "</a>")
                            . li(_("the ") . "<a target='_doc' href='https://apigoat.com/docs/api/rest-api-basics/'>" . _("Overall query/response dynamic") . "</a>")
                    ),
                '', "class='form-row'"
            );
        }

        // --- Assemble the tabbed card. Overview is the default tab. ---
        $tabDefs = [
            [$slug('overview'), _('Overview'), $overview],
            [$slug('settings'), _('Settings'), $settings],
        ];
        if ($hasAPI) {
            $tabDefs[] = [$slug('apisec'), _('API security'), $apisec];
        }

        $tabButtons = '';
        $tabPanes   = '';
        $first      = true;
        foreach ($tabDefs as [$tabId, $tabLabel, $paneBody]) {
            $activeCls  = $first ? ' is-active' : '';
            $selected   = $first ? 'true' : 'false';
            $hiddenAttr = $first ? '' : ' hidden';
            $tabButtons .= "<button type='button' class='welcome-tab-btn" . $activeCls . "' role='tab' data-tab='" . $tabId . "' aria-selected='" . $selected . "'>" . htmlspecialchars($tabLabel) . "</button>";
            $tabPanes   .= "<div id='" . $tabId . "' class='welcome-tab-pane" . $activeCls . "' role='tabpanel' data-tab='" . $tabId . "'" . $hiddenAttr . ">" . $paneBody . "</div>";
            $first = false;
        }

        $cards = div(
            "<div class='welcome-tabnav' role='tablist'>" . $tabButtons . "</div>"
                . "<div class='welcome-tab-panes sw-body'>" . $tabPanes . "</div>",
            '', "class='sw-drawer proto-form welcome-tabbed-card'"
        );

        // Self-contained baseline so the welcome screen looks right even before
        // _welcomev2.scss is compiled into main.css. Scoped to .welcome-screen
        // so it never bleeds into other views; compiled SCSS overrides it.
        $welcomeBaseline = <<<'CSS'
.welcome-screen { padding: 24px; max-width: 860px; margin: 0 auto; box-sizing: border-box; --surface: #ffffff; --colorLight: #ffffff; --line: #e3e8ee; --line-hard: #cdd5df; }
.welcome-screen .welcome-greeting { margin-bottom: 20px; }
.welcome-screen .welcome-greeting h1 { margin: 0 0 4px; font-size: 24px; font-weight: 600; color: #0a2540; }
.welcome-screen .welcome-date { color: rgba(0,0,0,0.55); font-size: 13px; }
.welcome-screen .welcome-stack { display: flex; flex-direction: column; gap: 16px; }
.welcome-screen .sw-drawer.proto-form { background: #fff; border: 1px solid #e3e8ee; border-radius: 12px; box-shadow: 0 1px 3px rgba(10,37,64,0.06); overflow: hidden; }
.welcome-screen .welcome-tabnav { display: flex; gap: 4px; padding: 6px 10px 0; border-bottom: 1px solid #eef1f5; background: #fafbfd; overflow-x: auto; }
.welcome-screen .welcome-tab-btn { appearance: none; background: transparent; border: none; padding: 10px 14px; font-size: 13px; font-weight: 600; color: #8898aa; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; }
.welcome-screen .welcome-tab-btn:hover { color: #0a2540; }
.welcome-screen .welcome-tab-btn.is-active { color: #0a2540; border-bottom-color: #00d1b2; }
.welcome-screen .welcome-tab-btn:focus { outline: none; }
.welcome-screen .welcome-tab-btn:focus-visible { box-shadow: 0 0 0 3px rgba(10,37,64,0.12); border-radius: 4px; }
.welcome-screen .welcome-tab-panes { padding: 16px; }
.welcome-screen .welcome-tab-pane[hidden] { display: none; }
.welcome-screen .welcome-status-pill { display: inline-flex; align-items: center; gap: 10px; padding: 8px 14px; border-radius: 999px; border: 1px solid #e3e8ee; background: #f7fafc; margin-bottom: 16px; }
.welcome-screen .welcome-status-pill form { margin: 0; display: inline-flex; align-items: center; gap: 10px; }
.welcome-screen .welcome-status-dot { width: 9px; height: 9px; border-radius: 50%; background: #8898aa; }
.welcome-screen .welcome-status-pill.is-prod .welcome-status-dot { background: #00d1b2; }
.welcome-screen .welcome-status-pill.is-dev .welcome-status-dot { background: #f5a623; }
.welcome-screen .welcome-status-text { font-size: 13px; font-weight: 600; color: #0a2540; }
.welcome-screen .welcome-status-pill input[type="checkbox"] { position: absolute; opacity: 0; width: 1px; height: 1px; }
.welcome-screen .welcome-status-toggle { position: relative; display: inline-block; width: 42px; height: 24px; cursor: pointer; }
.welcome-screen .welcome-status-toggle::before { content: ""; position: absolute; inset: 0; border-radius: 12px; background: #e3e8ee; transition: background .18s ease; }
.welcome-screen .welcome-status-toggle::after { content: ""; position: absolute; left: 2px; top: 2px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(10,37,64,0.16); transition: left .18s cubic-bezier(0.32,0.72,0.32,1); }
.welcome-screen .welcome-status-pill input[type="checkbox"]:checked + .welcome-status-toggle::before { background: #00d1b2; }
.welcome-screen .welcome-status-pill input[type="checkbox"]:checked + .welcome-status-toggle::after { left: 20px; }
.welcome-screen .welcome-stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; margin-bottom: 18px; }
.welcome-screen .welcome-stat-card { display: flex; flex-direction: column; gap: 4px; padding: 16px; border: 1px solid #e3e8ee; border-radius: 12px; background: #fff; text-decoration: none; transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease; }
.welcome-screen .welcome-stat-card:hover { border-color: #00d1b2; box-shadow: 0 2px 10px rgba(10,37,64,0.08); transform: translateY(-1px); }
.welcome-screen .welcome-stat-count { font-size: 26px; font-weight: 700; color: #0a2540; line-height: 1; }
.welcome-screen .welcome-stat-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: #8898aa; }
.welcome-screen .welcome-quicklinks { display: flex; flex-wrap: wrap; gap: 8px; }
.welcome-screen .welcome-quicklink { display: inline-block; padding: 7px 14px; border-radius: 999px; border: 1px solid #e3e8ee; background: #fff; font-size: 13px; font-weight: 600; color: #0a2540; text-decoration: none; transition: border-color .15s ease, color .15s ease; }
.welcome-screen .welcome-quicklink:hover { border-color: #00d1b2; color: #009b82; }
.welcome-screen .welcome-settings-group { margin-bottom: 18px; }
.welcome-screen .welcome-settings-group:last-child { margin-bottom: 0; }
.welcome-screen .welcome-settings-grouphdr { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #8898aa; padding: 0 0 8px; margin-bottom: 8px; border-bottom: 1px solid #eef1f5; }
.proto-screen.welcome-screen .welcome-tab-pane .form-row { padding: 10px 0; }
.proto-screen.welcome-screen .welcome-tab-pane .form-row form { margin: 0; }
.proto-screen.welcome-screen .welcome-tab-pane .form-row > form > label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: #8898aa; margin-bottom: 6px; }
.proto-screen.welcome-screen .welcome-tab-pane .form-row input[type="text"] { width: 100%; height: 38px; padding: 0 12px; border: 1px solid #e3e8ee; border-radius: 8px; font-size: 14px; background: #fff; color: #0a2540; box-sizing: border-box; }
.proto-screen.welcome-screen .welcome-tab-pane .form-row input[type="text"]:focus { outline: none; border-color: #00d1b2; box-shadow: 0 0 0 3px rgba(0,209,178,0.12); }
.proto-screen.welcome-screen .welcome-tab-pane .form-row .explain { margin-top: 6px; font-size: 12px; color: #8898aa; line-height: 1.45; }
.proto-screen.welcome-screen .welcome-tab-pane .form-row ul { margin: 8px 0 0; padding-left: 18px; font-size: 13px; line-height: 1.6; color: #0a2540; }
.proto-screen.welcome-screen .welcome-tab-pane .form-row a { color: #0a2540; text-decoration: underline; }
.proto-screen.welcome-screen .welcome-tab-pane .form-row a:hover { color: #00d1b2; }
CSS;

        // Self-contained tab toggle so the welcome tabs work even when the
        // cached JS bundle predates drawer.js's handler. Document-level
        // delegation; window-flag guard prevents double-binding once
        // drawer.js loads.
        $tabScript = <<<'JS'
(function(){
  if (window.__gcWelcomeTabsInline) return;
  window.__gcWelcomeTabsInline = true;
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.welcome-tab-btn');
    if (!btn) return;
    e.preventDefault();
    var nav = btn.closest('.welcome-tabnav');
    if (!nav) return;
    var target = btn.getAttribute('data-tab');
    if (!target) return;
    nav.querySelectorAll('.welcome-tab-btn').forEach(function (b) {
      var on = (b === btn);
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    var scope = nav.closest('.welcome-tabbed-card') || document;
    scope.querySelectorAll('.welcome-tab-pane').forEach(function (p) {
      var on = (p.getAttribute('data-tab') === target);
      p.classList.toggle('is-active', on);
      if (on) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden', ''); }
    });
  }, true);
})();
JS;
        $return['html'] = swheader() . "<style>" . $welcomeBaseline . "</style>" . div(
            $greeting . div($cards, '', "class='welcome-stack'"),
            '',
            "class='proto-screen welcome-screen'"
        ) . "<script" . gcNonceAttr() . ">" . $tabScript . "</script>";

        $return['onReadyJs'] = "
    document.querySelectorAll('[ag_save=Config]').forEach(function (__cfg) {
        __cfg.addEventListener('change', function () {
            var config = this.getAttribute('config');
            var value = 'dev';
            if (config == 'app_status') {
                if (this.checked) {
                    value = 'prod';
                }
                var __valEl = this.parentElement.querySelector('#Value');
                if (__valEl) { __valEl.value = value; }
                // Live-reflect the pill state without waiting for a reload.
                var __pill = this.closest('.welcome-status-pill');
                if (__pill) {
                    __pill.classList.toggle('is-prod', value === 'prod');
                    __pill.classList.toggle('is-dev', value !== 'prod');
                    var __txt = __pill.querySelector('.welcome-status-text');
                    if (__txt) { __txt.textContent = (value === 'prod') ? '" . addslashes(_('Production')) . "' : '" . addslashes(_('Development')) . "'; }
                }
            }
            var __idEl = this.parentElement.querySelector('#IdConfig');
            var id = __idEl ? __idEl.value : '';
            // Rebuild jQuery .serialize() over the form's [ag_save=Config] inputs:
            // same field names/encoding the server contract expects.
            var __form = this.closest('#form_' + config);
            var __ser = new URLSearchParams();
            if (__form) {
                __form.querySelectorAll('[ag_save=Config]').forEach(function (__f) {
                    if (!__f.name || __f.disabled) { return; }
                    var __t = (__f.type || '').toLowerCase();
                    if ((__t === 'checkbox' || __t === 'radio') && !__f.checked) { return; }
                    if (__f.tagName === 'SELECT' && __f.multiple) {
                        Array.prototype.forEach.call(__f.selectedOptions, function (__o) {
                            __ser.append(__f.name, __o.value);
                        });
                        return;
                    }
                    __ser.append(__f.name, __f.value);
                });
            }
            var __body = new URLSearchParams();
            __body.set('d', __ser.toString());
            __body.set('ui', 'tabsContain');
            __body.set('jet', 'swWarn');
            // Config saveUpdate() unconditionally urldecode()s the ip/pc request
            // params; this custom save omitted them, so on PHP 8.x urldecode(null)
            // is a fatal TypeError. Send them empty (matching the standard save
            // contract) so a plain Config edit can't 500.
            __body.set('ip', '');
            __body.set('pc', '');
            fetch(_SITE_URL + 'Config/update/' + id, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
                body: __body.toString()
            }).then(function (r) { return r.text(); }).then(function (response) {
                var __d = document.getElementById('tabsContain');
                if (!__d) { return; }
                // Mirror jQuery .append(response): insert markup AND execute any
                // returned <script> (the /update response is a swWarn script body).
                // insertAdjacentHTML/innerHTML alone never runs scripts.
                var __tmp = document.createElement('div');
                __tmp.innerHTML = response;
                while (__tmp.firstChild) {
                    var __n = __tmp.firstChild;
                    if (__n.tagName === 'SCRIPT') {
                        var __s2 = document.createElement('script');
                        if (__n.src) { __s2.src = __n.src; } else { __s2.textContent = __n.textContent; }
                        __tmp.removeChild(__n);
                        __d.appendChild(__s2);
                    } else {
                        __d.appendChild(__n);
                    }
                }
            });
        });
    });
        ";
        return $return;
    }
}
