<?php

namespace ApiGoat\Views;

use Psr\Http\Message\ServerRequestInterface as Request;
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

    // New structure (post-2026-05-23 restyle):
    // .proto-screen.welcome-screen
    //   .welcome-greeting (h1 + .welcome-date)
    //   .welcome-stack
    //     .sw-drawer.proto-form (App state card, always first when present)
    //       .sw-header > .form-nav > .nav-title : "App state"
    //       .sw-body > .form-row : checkbox form
    //     .sw-drawer.proto-form (one per Config Category)
    //       .sw-header > .form-nav > .nav-title : category name
    //       .sw-body > .form-row × N : label + Value input + IdConfig hidden + .explain
    //     .sw-drawer.proto-form (API security, only when $hasAPI)
    //       .sw-header > .form-nav > .nav-title : "API security"
    //       .sw-body > .form-row : info text + ul of doc links
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

        // --- Collect Config rows by category ---
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

        $cards = '';

        // App state card — always rendered on top, never inside the tabs.
        if ($appStatusRow) {
            $checked = ($appStatusRow->getValue() === 'prod') ? 'checked=true' : '';
            $cards .= div(
                div(
                    div(div(_('App state'), '', "class='nav-title'"), '', "class='form-nav'"),
                    '', "class='sw-header'"
                ) . div(
                    div(
                        form(
                            label(_('Current state of the App.'))
                                . input('checkbox', 'config', 'app_status', $checked . " config='app_status' ag_save='Config'")
                                . label(_('Development / Production'), "for='config'")
                                . input('hidden', 'IdConfig', $appStatusId, "ag_save='Config'")
                                . input('hidden', 'Value', '', "ag_save='Config'")
                                . div(_('Developement version will be slower. It will produce logs and allow unknowned API access.'), '', "class='explain'"),
                            "id='form_app_status'"
                        ),
                        '', "class='form-row'"
                    ),
                    '', "class='sw-body'"
                ),
                '', "class='sw-drawer proto-form'"
            );
        }

        // Everything else (per-Category Config blocks + optional API
        // security block) goes into a single tabbed card. Tabs share the
        // same data-tab/.is-active/[hidden] contract used by Form.php +
        // drawer.js so once the JS bundle catches up they're handled by
        // the same vanilla logic; the inline <script> below covers the
        // pre-rebuild window.
        $tabButtons = '';
        $tabPanes = '';
        $firstTab = true;
        $slug = function ($s) {
            return 'welTab_' . preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $s);
        };

        foreach ($categoryBuckets as $category => $rows) {
            $tabId = $slug($category);
            $activeCls = $firstTab ? ' is-active' : '';
            $selected = $firstTab ? 'true' : 'false';
            $hiddenAttr = $firstTab ? '' : ' hidden';

            $tabButtons .= "<button type='button' class='welcome-tab-btn" . $activeCls . "' role='tab' data-tab='" . $tabId . "' aria-selected='" . $selected . "'>" . htmlspecialchars($category) . "</button>";

            $paneBody = '';
            foreach ($rows as $Config) {
                $paneBody .= div(
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
            $tabPanes .= "<div id='" . $tabId . "' class='welcome-tab-pane" . $activeCls . "' role='tabpanel' data-tab='" . $tabId . "'" . $hiddenAttr . ">" . $paneBody . "</div>";
            $firstTab = false;
        }

        if ($hasAPI) {
            $tabId = $slug('apisec');
            $activeCls = $firstTab ? ' is-active' : '';
            $selected = $firstTab ? 'true' : 'false';
            $hiddenAttr = $firstTab ? '' : ' hidden';

            $tabButtons .= "<button type='button' class='welcome-tab-btn" . $activeCls . "' role='tab' data-tab='" . $tabId . "' aria-selected='" . $selected . "'>" . _('API security') . "</button>";

            $tabPanes .= "<div id='" . $tabId . "' class='welcome-tab-pane" . $activeCls . "' role='tabpanel' data-tab='" . $tabId . "'" . $hiddenAttr . ">"
                . div(
                    div(_("Before using the API, please make yourself familiar with:"))
                        . ul(
                            li(_("the ") . "<a target='_doc' href='https://apigoat.com/docs/your-app/api-acl/'>" . _("Rule Based Access List") . "</a>")
                                . li(_("the ") . "<a target='_doc' href='https://apigoat.com/docs/your-app/permissions/'>" . _("App Permissions") . "</a>")
                                . li(_("the ") . "<a target='_doc' href='https://apigoat.com/docs/api/rest-api-basics/'>" . _("Overall query/response dynamic") . "</a>")
                        ),
                    '', "class='form-row'"
                )
                . "</div>";
            $firstTab = false;
        }

        if ($tabButtons !== '') {
            $cards .= div(
                "<div class='welcome-tabnav' role='tablist'>" . $tabButtons . "</div>"
                . "<div class='welcome-tab-panes sw-body'>" . $tabPanes . "</div>",
                '', "class='sw-drawer proto-form welcome-tabbed-card'"
            );
        }

        // Self-contained baseline so the welcome screen looks right
        // even before _welcomev2.scss / _formv2.scss are compiled into
        // main.css (which only happens on `gc build` followed by an
        // asset-pipeline cache miss). Scoped to .welcome-screen so it
        // never bleeds into other views; compiled SCSS will override
        // anything where the cascade prefers it.
        $welcomeBaseline = <<<'CSS'
.welcome-screen { padding: 24px; max-width: 760px; margin: 0 auto; box-sizing: border-box; }
.welcome-screen .welcome-greeting { margin-bottom: 20px; }
.welcome-screen .welcome-greeting h1 { margin: 0 0 4px; font-size: 24px; font-weight: 600; color: #0a2540; }
.welcome-screen .welcome-date { color: rgba(0,0,0,0.55); font-size: 13px; }
.welcome-screen .welcome-stack { display: flex; flex-direction: column; gap: 16px; }
.welcome-screen .sw-drawer.proto-form { background: #fff; border: 1px solid #e3e8ee; border-radius: 12px; box-shadow: 0 1px 3px rgba(10,37,64,0.06); overflow: hidden; }
.welcome-screen .sw-header { padding: 12px 18px; border-bottom: 1px solid #eef1f5; background: #fafbfd; }
.welcome-screen .form-nav { display: flex; align-items: center; }
.welcome-screen .nav-title { font-size: 14px; font-weight: 600; color: #0a2540; letter-spacing: -0.005em; }
.welcome-screen .sw-body { padding: 6px 0; }
.welcome-screen .form-row { padding: 12px 18px; border-bottom: 1px solid #eef1f5; }
.welcome-screen .form-row:last-child { border-bottom: none; }
.welcome-screen .form-row form { margin: 0; }
.welcome-screen .form-row label { display: inline-block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: #8898aa; margin-bottom: 6px; }
.welcome-screen .form-row label[for] { font-weight: 500; text-transform: none; letter-spacing: 0; color: #0a2540; margin-left: 6px; font-size: 13px; vertical-align: middle; }
.welcome-screen .form-row input[type="text"] { width: 100%; height: 38px; padding: 0 12px; border: 1px solid #e3e8ee; border-radius: 8px; font-size: 14px; background: #fff; color: #0a2540; box-sizing: border-box; }
.welcome-screen .form-row input[type="text"]:focus { outline: none; border-color: #0a2540; box-shadow: 0 0 0 3px rgba(10,37,64,0.08); }
.welcome-screen .form-row input[type="checkbox"] { position: absolute; opacity: 0; width: 1px; height: 1px; }
.welcome-screen .form-row input[type="checkbox"] + label[for] { position: relative; display: inline-block; min-height: 25px; padding-right: 50px; vertical-align: middle; cursor: pointer; }
.welcome-screen .form-row input[type="checkbox"] + label[for]::before { content: ""; position: absolute; right: 0; top: 0; width: 42px; height: 25px; border-radius: 13px; background: #e3e8ee; transition: background .18s ease; }
.welcome-screen .form-row input[type="checkbox"] + label[for]::after { content: ""; position: absolute; right: 19px; top: 2px; width: 21px; height: 21px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(10,37,64,0.16); transition: right .18s cubic-bezier(0.32,0.72,0.32,1); }
.welcome-screen .form-row input[type="checkbox"]:checked + label[for]::before { background: #00d1b2; }
.welcome-screen .form-row input[type="checkbox"]:checked + label[for]::after { right: 2px; }
.welcome-screen .form-row input[type="checkbox"]:focus + label[for]::before { box-shadow: 0 0 0 3px #d4f3ed; }
.welcome-screen .form-row .explain { margin-top: 6px; font-size: 12px; color: #8898aa; line-height: 1.45; }
.welcome-screen .form-row ul { margin: 8px 0 0; padding-left: 18px; font-size: 13px; line-height: 1.6; color: #0a2540; }
.welcome-screen .form-row a { color: #0a2540; text-decoration: underline; }
.welcome-screen .form-row a:hover { color: #df1b41; }
.welcome-screen .welcome-tabnav { display: flex; gap: 4px; padding: 6px 10px 0; border-bottom: 1px solid #eef1f5; background: #fafbfd; overflow-x: auto; }
.welcome-screen .welcome-tab-btn { appearance: none; background: transparent; border: none; padding: 10px 14px; font-size: 13px; font-weight: 600; color: #8898aa; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; letter-spacing: -0.005em; }
.welcome-screen .welcome-tab-btn:hover { color: #0a2540; }
.welcome-screen .welcome-tab-btn.is-active { color: #0a2540; border-bottom-color: #0a2540; }
.welcome-screen .welcome-tab-btn:focus { outline: none; }
.welcome-screen .welcome-tab-btn:focus-visible { box-shadow: 0 0 0 3px rgba(10,37,64,0.12); border-radius: 4px; }
.welcome-screen .welcome-tab-panes { padding: 6px 0; }
.welcome-screen .welcome-tab-pane[hidden] { display: none; }
CSS;
        // Self-contained tab toggle so the new welcome tabs work even
        // when the cached JS bundle predates drawer.js's .sw-tabnav
        // handler. Document-level delegation; window-flag guard prevents
        // double-binding once drawer.js does load.
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
        ) . "<script>" . $tabScript . "</script>";

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
