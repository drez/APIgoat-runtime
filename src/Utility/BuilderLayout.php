<?php
namespace ApiGoat\Utility;

use Selective\Config\Configuration;

/**
 * Description of BuilderLayout
 *
 * @author sysadmin
 */
class BuilderLayout
{

    private $incCss;
    private $title;
    private $builderMenus;
    private $htmlHeader;
    private $settings;
    private $js;
    public $showLeftPannel = true;

    function __construct(BuilderMenus $BuilderMenus)
    {
        include _BASE_DIR . 'config/assets.php';
        $Config             = new Configuration(require _BASE_DIR . 'config/settings.php');
        $this->settings     = $Config->getArray('admin_panel');
        $siteDescription    = '';
        $siteKeywords       = '';
        $favicon            = '';
        $headAuthor         = '';
        $this->builderMenus = $BuilderMenus;

        $this->incCss = $Assets->css() . $AssetsAdmin->css();
        if (defined('_TITLE_PREFIX')) {
            $this->title = _TITLE_PREFIX . " / " . $siteTitle;
        }
        $csrfMeta = '';
        if (defined('_AUTH_VAR') && isset($_SESSION[_AUTH_VAR]) && is_object($_SESSION[_AUTH_VAR]) && method_exists($_SESSION[_AUTH_VAR], 'getCsrf')) {
            $csrfMeta = "<meta name='csrf-token' content='" . htmlspecialchars($_SESSION[_AUTH_VAR]->getCsrf(), ENT_QUOTES) . "'>\n";
        }
        $vapidPublicKey = function_exists('env') ? (env('VAPID_PUBLIC_KEY') ?: '') : '';
        // Floating notifications pill (public/js/pwa.js): OFF by default so the
        // Account page hosts the canonical control. Set GC_NOTIF_PILL=1 in a
        // project .env to restore the zero-setup floating pill.
        $notifPillOn = function_exists('env') ? filter_var(env('GC_NOTIF_PILL'), FILTER_VALIDATE_BOOLEAN) : false;
        $pillOffLiteral = $notifPillOn ? 'false' : 'true';
        $gcTheme = $this->resolveTheme();
        $headjs = $csrfMeta . "<script type='text/javascript'>
    let _SITE_URL = '" . addslashes(_SITE_URL) . "';
    let _VAPID_PUBLIC_KEY = '" . addslashes($vapidPublicKey) . "';
    window.gcNotifPillOff = " . $pillOffLiteral . ";
    (function () {
        var ok = ['mint', 'ink', 'indigo', 'terracotta', 'graphite'];
        var t = " . json_encode($gcTheme) . ";
        try {
            if (t) { localStorage.setItem('gcTheme', t); }
            else { t = localStorage.getItem('gcTheme') || ''; }
        } catch (e) {}
        if (ok.indexOf(t) > -1 && t !== 'mint') {
            document.documentElement.setAttribute('data-theme', t);
        }
    }());
</script>";

        // PWA meta tags and icons for iOS / Android / Windows
        // apple-mobile-web-app-title is per-project — prefer the
        // configured _SITE_TITLE, fall back to ucfirst(_PROJECT_NAME),
        // and only default to "App" when neither is defined.
        $pwaTitle = '';
        if (defined('_SITE_TITLE') && _SITE_TITLE !== '') {
            $pwaTitle = (string) _SITE_TITLE;
        } elseif (defined('_PROJECT_NAME') && _PROJECT_NAME !== '') {
            $pwaTitle = ucfirst((string) _PROJECT_NAME);
        } else {
            $pwaTitle = 'App';
        }
        $pwaHeaders = '
<style>html{background-color:var(--bg,#ffffff)}</style>
<link rel="manifest" href="' . _SITE_URL . 'manifest.webmanifest">
<link rel="apple-touch-icon" sizes="180x180" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<link rel="apple-touch-icon" sizes="152x152" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<link rel="apple-touch-icon" sizes="167x167" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<link rel="apple-touch-icon" sizes="120x120" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<link rel="icon" type="image/png" sizes="512x512" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<link rel="icon" type="image/png" sizes="192x192" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="' . htmlspecialchars($pwaTitle, ENT_QUOTES) . '">
<meta name="theme-color" content="#ffffff">
<meta name="msapplication-TileImage" content="' . _SITE_URL . 'public/img/windows/Square150x150Logo.scale-200.png">
<meta name="msapplication-TileColor" content="#ffffff">
';

        $this->htmlHeader = htmlHeader($this->title, $this->incCss, $siteDescription, $siteKeywords, $pwaHeaders . $headjs . $AssetsHead->js() . $AssetsAdmin->js() . $Assets->js(), $favicon, $headAuthor);

    }

    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * renderXHR
     *
     * @param  array|string $content
     * @return string
     *
     * ['html' => '', 'js' => '', 'onReadyJs' => '']
     */
    public function renderXHR($content)
    {
        if (empty($content)) {
            return "The response is empty.";
        }

        if (! empty($content['html']) || ! empty($content['js']) || ! empty($content['onReadyJs'])) {
            return $content['html'] . ($content['js'] ?? '')
            . scriptReady(trim($content['onReadyJs']));
        } else {
            if (! is_array($content)) {
                return $content;
            } else {
                return json_encode($content);
            }
        }

    }

    public function renderLogin($content)
    {
        $print =
        docType()
        . htmlTag(
            $this->htmlHeader
            . body(
                div(
                    div('', "loader", " class='hide' ")
                    . div(
                        div(
                            div($content['html'], 'mainContent', "class=''"),
                            "centered",
                            "style='width:100%;position:relative;text-align:center;margin:auto;'"
                        )
                        . div("", '', "class='wCtFooter' style=''"),
                        'fullWH2',
                        "style='width:100%;height:100%;'"
                    ),
                    'fullWH',
                    "style='width:100%;height:100%;'"
                ),
                " id='body' class=''"
            ),
            " id='html' "
        )
        . $content['js']
        . scriptReady(trim($content['onReadyJs']));

        return $print;
    }

    /**
     * render
     *
     * @param  array|null $content
     * @return string
     * ['html'=>'', 'js' =>'', 'pagerRow' => '', 'onReadyJs' => '']
     */
    public function render($content)
    {
        header('Cache-Control: no-store');

        if (empty($content['html'])) {
            return "Response is empty, does the service exists?";
        }

        $pageLoader = '<div id="pageLoader" class="page-loader">
<img src="' . _SITE_URL . 'public/img/ios/512.png" class="page-loader-logo" alt="">
<div class="page-loader-spinner"></div>
</div>
<script>
window.addEventListener("load",function(){var l=document.getElementById("pageLoader");if(l){l.style.opacity="0";setTimeout(function(){l.style.display="none";document.documentElement.style.backgroundColor="";},300);}});
if("serviceWorker"in navigator&&navigator.serviceWorker.controller){navigator.serviceWorker.addEventListener("message",function(e){if(e.data&&e.data.type==="SW_AUTH_RELOAD")window.location.reload();});}
</script>';

        $leftPannel = '';
        $drawer = '';
        $dim = '';
        $topbar = '';
        $pannelStylesOverride = '';

        if($this->showLeftPannel){
            // Build the menu HTML once — Menu::getMenu() accumulates state,
            // so it must not be called twice.
            $menusHtml = $this->builderMenus->getMenus();

            $leftPannel = div(
            div(
                div(
                    div(
                        //href(img($logoAdmin),_SITE_URL,'class="logo-wrapper"')
                        $this->getTopNav(),
                        '',
                        'class="top-nav"'
                    )
                    . nav($menusHtml, 'class="ac-nav"'),
                    '',
                    'class="left-panel-content" '
                ),
                '',
                'class="left-panel-wrapper" '
            ),
            '',
            // Inline display:none — the legacy sidebar is fully replaced by
            // #appDrawer (.proto-drawer) and only kept in the DOM for the
            // impersonation JS. The _formv2 kill rule (`body .left-panel
            // { display:none !important }`) lives in main.css, which clients
            // may cache stale for weeks (no hash on raw css adds historically)
            // — an inline style is cache-immune.
            'class="left-panel" style="display:none" '
        );

            // Guideline mobile drawer (SCSS in _formv2: .proto-drawer /
            // .proto-dim). Reuses the same nav markup + active state.
            // Toggled by public/js/app/shell.js (vanilla, no jQuery).
            $gcUser = (string) ($_SESSION[_AUTH_VAR]->get('username') ?? '');
            $gcRole = (string) ($_SESSION[_AUTH_VAR]->get('group') ?? '');
            $gcInit = strtoupper(mb_substr(trim($gcUser) !== '' ? $gcUser : 'U', 0, 2));
            // Project name from the site path (…/<project>/.admin/).
            $gcSegs = array_values(array_filter(explode('/', trim((string) (parse_url(_SITE_URL, PHP_URL_PATH) ?? ''), '/'))));
            $gcProj = 'Admin';
            foreach ($gcSegs as $gcS) {
                if ($gcS !== '' && strpos($gcS, '.admin') === false) { $gcProj = $gcS; }
            }
            $gcProj = ucfirst($gcProj);
            $drawer = div(
                div(
                    href("<i class='ri-apps-2-fill'></i>", _SITE_URL, " class='dr-logo-mark' title='" . _('Dashboard') . "' ")
                    . div(
                        span(htmlspecialchars($gcProj))
                        . ($gcUser !== '' ? "<small>" . htmlspecialchars($gcUser) . "</small>" : ''),
                        '',
                        "class='dr-logo-text'"
                    )
                    . button("<i class='ri-close-line'></i>", "type='button' class='dr-close' aria-label='" . _('Close') . "'"),
                    '',
                    "class='dr-head'"
                )
                . div(
                    "<i class='ri-search-line'></i>"
                    . input('text', 'drawerFilter', '', "class='dr-search-input' placeholder='" . _('Jump to…') . "' autocomplete='off'"),
                    '',
                    "class='dr-search'"
                )
                . div(
                    nav($menusHtml, 'class="ac-nav"'),
                    '',
                    "class='dr-scroll'"
                )
                . $this->getImpersonatePanel()
                . div(
                    div($gcInit, '', "class='dr-avatar'")
                    . div(
                        href($gcUser !== '' ? htmlspecialchars($gcUser) : _('User'), _SITE_URL . 'Account', "class='dr-username' title='" . _('My account') . "'")
                        . span($gcRole !== '' ? htmlspecialchars($gcRole) : '&nbsp;', "class='dr-userrole'"),
                        '',
                        "class='dr-userinfo'"
                    )
                    . $this->getImpersonateIcon()
                    . href("<i class='ri-logout-box-r-line'></i>", _SITE_URL . 'Authy/logout', "class='dr-signout' title='" . _('Logout') . "' aria-label='" . _('Logout') . "'"),
                    '',
                    "class='dr-footer'"
                ),
                'appDrawer',
                "class='proto-drawer'"
            );
            $dim = div('', 'appDim', "class='proto-dim'");

            // Global topbar — the drawer opener must exist on EVERY page,
            // not only list pages (the list emits its own in-header
            // .menu-btn; SCSS hides this bar there to avoid two
            // hamburgers). Without this, non-list pages (home, full-page
            // edit) had no way to open #appDrawer.
            $gcEntity = (string) ($this->builderMenus->getRequested() ?: '');
            $gcCrumb = $gcEntity !== '' ? $gcEntity : _('Home');
            $topbar = div(
                button("<i class='ri-menu-line'></i>", "type='button' class='menu-btn' aria-label='" . _('Menu') . "'")
                . span(htmlspecialchars($gcCrumb), "class='app-topbar-title'")
                . div('', 'appTopbarActions', "class='app-topbar-actions'"),
                '',
                "class='app-topbar'"
            );
        }else{
            $pannelStylesOverride = "style='width: 100%;transform: none;'";
        }
       

        $print =
        docType()
        . htmlTag(
            $this->htmlHeader
            . body(
                $pageLoader
                . $leftPannel
                . $drawer
                . $dim
                . div(
                    $topbar
                    . div(div($content['html'], 'tabsContain'), '', 'class="content-wrapper"')
                    . div('', 'editPane', 'class="edit-pane-hidden"'),
                    '',
                    'class="center-panel" '.$pannelStylesOverride
                )

                . div('', 'editDialog', 'style=""')

                . div(
                    div(
                        input('hidden', 'session_expired_user', '', "id='session_expired_user'  autocomplete='off' class='sw-input' style='width:100%;margin-bottom:8px;box-sizing:border-box;'")
                        . input('hidden', 'session_expired_pass', '', "id='session_expired_pass'  autocomplete='off' class='sw-input' style='width:100%;box-sizing:border-box;'")
                        . div('', 'session_expired_error', "class='hide' style='color:#c0392b;margin-top:8px;font-size:0.85em;'"),
                        '',
                        "class='mainForm'"
                    ),
                    'sessionExpiredDialog',
                    "title='" . _('Session Expired') . "'"
                )

                . $this->js,
                " id='body' class='" . (isset($bodyClass) ? $bodyClass : '') . "' style='height:100%;'"
            ),
            " id='html_build' "
        )
        . (isset($content['js']) ? $content['js'] : '')
        . scriptReady((isset($content['onReadyJs']) ? trim($content['onReadyJs']) : ''));

        return $print;
    }

    /**
     * getTopNav
     *
     * @return string
     */
    public function getTopNav()
    {

        $menus    = ['profil', 'support', 'dashboard'];
        $items    = '';
        $settings = $this->settings['top_nav'];
        foreach ($menus as $menu) {
            if (isset([$menu]['url'])) {
                $items .= li(href(span(_($settings[$menu]['caption'])), $settings[$menu]['url'], 'title="' . $settings[$menu]['title'] . '" class="icon ' . $menu . '"'), "class='right'");
            }
        }

        $nav = ul(
            li(href(img(_SITE_URL . vendor_logo), vendor_url, 'class="logo-wrapper"'))
            . li(href(span(_("Home")), _SITE_URL, 'title="Home" class="icon home"'), "class='right'")
            . $items
            . li(href(span(_("Menu")), "Javascript:void(0);", 'title="Menu" class="icon menu trigger-menu"')),
            'class="nav"'
        );

        return $nav;
    }

    /**
     * Per-user theme for html[data-theme]. Session-cached after one
     * AuthyQuery lookup; '' pre-auth (the head script then falls back
     * to the localStorage device echo). Guards make projects whose
     * authy table predates the theme column resolve to the default.
     */
    private function resolveTheme()
    {
        $allowed = ['mint', 'ink', 'indigo', 'terracotta', 'graphite'];
        if (! defined('_AUTH_VAR') || ! isset($_SESSION[_AUTH_VAR]) || ! is_object($_SESSION[_AUTH_VAR])) {
            return '';
        }
        $sess = $_SESSION[_AUTH_VAR];
        $cached = isset($sess->sessVar['Theme']) ? $sess->sessVar['Theme'] : null;
        if (is_string($cached) && in_array($cached, $allowed, true)) {
            return $cached;
        }
        $userId = (int) ($sess->get('id') ?? 0);
        if (! $userId) {
            return '';
        }
        $theme = 'mint';
        if (class_exists('\App\AuthyQuery')) {
            $authy = \App\AuthyQuery::create()->findPk($userId);
            if ($authy && method_exists($authy, 'getTheme')) {
                $t = (string) $authy->getTheme();
                if (in_array($t, $allowed, true)) {
                    $theme = $t;
                }
            }
        }
        $sess->sessVar['Theme'] = $theme;
        return $theme;
    }

    /**
     * Icon button that lives in the drawer footer; toggles the
     * impersonation panel rendered by getImpersonatePanel(). Empty when
     * the current session is not isRoot.
     *
     * Carries inline styles so it renders correctly even before
     * _formv2.scss is recompiled into main.css. The SCSS rules in
     * .proto-drawer .dr-impersonate-btn override these once compiled.
     */
    private function getImpersonateIcon()
    {
        if (empty($_SESSION[_AUTH_VAR]) || ! $_SESSION[_AUTH_VAR]->get('isRoot')) {
            return '';
        }
        $inlineStyle = 'width:32px;height:32px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;color:#8898aa;background:transparent;border:none;cursor:pointer;font-size:18px;padding:0;line-height:1;';
        return button(
            "<i class='ri-user-shared-line'></i>",
            "type='button' class='dr-impersonate-btn' aria-label='" . _('Impersonate') . "' title='" . _('Impersonate') . "' aria-controls='drImpersonatePanel' aria-expanded='false' style='" . $inlineStyle . "'"
        );
    }

    /**
     * Slide-up panel inside the drawer containing the impersonation
     * autocomplete. Hidden by default; the icon in dr-footer toggles
     * its .is-open class via shell.js. Empty when not isRoot.
     */
    private function getImpersonatePanel()
    {
        if (empty($_SESSION[_AUTH_VAR]) || ! $_SESSION[_AUTH_VAR]->get('isRoot')) {
            return '';
        }

        if (empty($_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'])) {
            $_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'] = bin2hex(random_bytes(16));
        }
        if (empty($_SESSION[_AUTH_VAR]->sessVar['IdAuthy'])) {
            $_SESSION[_AUTH_VAR]->sessVar['IdAuthy'] = $_SESSION[_AUTH_VAR]->get('id');
        }

        $username = (string) $_SESSION[_AUTH_VAR]->get('username');
        $idAuthy  = (string) $_SESSION[_AUTH_VAR]->sessVar['IdAuthy'];
        $csrf     = (string) $_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'];

        // `hidden` attribute provides a browser-native default-hide that
        // works without any compiled CSS. The inline <script> below
        // wires the toggle directly so it runs even when the bundled
        // JS (asset pipeline cache, keyed by buildId) hasn't been
        // refreshed yet. shell.js is intentionally NOT used for this
        // toggle — the inline script sets window.__gcImpersonateInline
        // as a sentinel any future bundled handler can check.
        $panelInlineStyle = 'padding:12px 14px;border-top:1px solid #e3e8ee;background:#fff;';
        $toggleScript = <<<'JS'
(function(){
  if (window.__gcImpersonateInline) return;
  window.__gcImpersonateInline = true;
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.dr-impersonate-btn');
    var panel = document.getElementById('drImpersonatePanel');
    if (!panel) return;
    if (btn) {
      e.preventDefault();
      var nowOpen = panel.hasAttribute('hidden');
      if (nowOpen) {
        panel.removeAttribute('hidden');
        btn.setAttribute('aria-expanded', 'true');
        var inp = panel.querySelector('input[name="IarcAutoc"]');
        if (inp) { try { inp.focus(); } catch (_) {} }
      } else {
        panel.setAttribute('hidden', '');
        btn.setAttribute('aria-expanded', 'false');
      }
      return;
    }
    if (!panel.hasAttribute('hidden') && !e.target.closest('.dr-impersonate-panel')) {
      panel.setAttribute('hidden', '');
      var openBtn = document.querySelector('.dr-impersonate-btn[aria-expanded="true"]');
      if (openBtn) { openBtn.setAttribute('aria-expanded', 'false'); }
    }
  }, true);
})();
JS;
        return div(
            div(_('Impersonate user'), '', "class='dr-impersonate-title' style='font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#8898aa;margin-bottom:6px;'")
            . div(
                form(
                    input('text', 'IarcAutoc', $username, " otherTabs=1 v='IARC' rid='IARC' placeholder='" . _('USER') . "' j='autocomplete' class='ui-autocomplete-input' style='width:100%;height:36px;padding:0 12px;border:1px solid #e3e8ee;border-radius:8px;font-size:14px;background:#fafbfd;color:#0a2540;box-sizing:border-box;'")
                    . input('hidden', 'Iarc', $idAuthy, "s='d'")
                    . input('hidden', 'IarcCsrf', $csrf, "s='d'"),
                    ' id="select-box-Authy" class="select-box-authy" data-authy="' . htmlspecialchars($idAuthy, ENT_QUOTES) . '" data-csrf="' . htmlspecialchars($csrf, ENT_QUOTES) . '"'
                ),
                '',
                "class='box-Authy'"
            ),
            'drImpersonatePanel',
            "class='dr-impersonate-panel' hidden style='" . $panelInlineStyle . "'"
        ) . "<script>" . $toggleScript . "</script>";
    }


    public function decorate(string $content, array $options)
    {
        switch ($options['type']) {
            case 'warning':
                $head         = div(h3('Warning'), '', "class='box-header'");
                $contentClass = 'box';
                $bodyClass    = 'bodybg';
                break;
        }

        if (isset($options['bottom'])) {
            $options['bottom'] = div($options['bottom'], '', "class='box-bottom'");
        }

        if ($content) {
            $content =
            docType()
            . htmlTag(
                $this->htmlHeader
                . body(
                    div(
                        $options['top']
                        . $head
                        . div(
                            div($content, '', "style='" . $options['content-style'] . "'")
                            . $options['bottom-inner'],
                            '',
                            "class='centered75 box-body'"
                        )
                        . $options['bottom'],
                        '',
                        "class='mainContent {$contentClass}'"
                    ),
                    "class='{$bodyClass}'"
                )
            );
        }
        return $content;
    }

    /**
     * decoratedForm
     *
     * @param  string $content
     * @param  string $name
     * @param  array $options ['addSave', 'idPk', 'idParent', 'destUi', 'onSave', 'button']
     * @return string
     */
    public static function decoratedForm($content, $name, $options = [])
    {
        if (! empty($options['addSave']) || ! empty($options['onSave'])) {
            $buttonName  = (! empty($options['button'])) ? $options['button'] : 'Save';
            $formSaveBar = div(
                div(input('button', "save$name", _($buttonName), ' class="button-link-blue can-save"')
                    . input('hidden', "formChanged$name", '', 'j="formChanged"')
                    . input('hidden', 'idPk', urlencode($options['idPk']), "s='d'")
                    . input('hidden', 'idParent', $options['idParent'], " s='d' pk")
                    , "", " class='divtd' colspan='2' style='text-align:right;'")
                , "", " class='divtr divbut' ");

            if ($options['addSave'] == 'yes') {
                // Vanilla port of the former jQuery $.fn.bindSave plugin (jquery
                // core removal, stage 5). Behaviour-matched to the old plugin:
                // gcScreens owns regular (non-panel) edit screens and binds its
                // own .nav-save handler, so step aside there exactly as the old
                // guard did; panels (data-model __panel__) keep this save flow.
                // The data-save-bound flag guards against the onReadyJs re-exec
                // double-bind (see screens.js inline-script pass).
                $editEvent = "
                (function () {
                    var __f = document.getElementById('form" . $name . "');
                    var __b = __f ? __f.querySelector('#save" . $name . "') : null;
                    if (!__b || __b.getAttribute('data-save-bound')) { return; }
                    if (window.gcScreens) {
                        var __sc = __b.closest ? __b.closest('.proto-screen[data-model]') : null;
                        if (__sc && __sc.getAttribute('data-model') !== '__panel__') { return; }
                    }
                    __b.setAttribute('data-save-bound', '1');
                    __b.addEventListener('click', function () {
                        __b.setAttribute('disabled', 'disabled');
                        document.body.style.cursor = 'progress';
                        __b.style.cursor = 'progress';
                        __b.classList.remove('ac-light-red');
                        __b.classList.add('ac-light-blue');
                        if (window.gcEditor) { gcEditor.syncWithin(__f); }
                        var __p = new URLSearchParams();
                        Array.prototype.forEach.call(__f.querySelectorAll('[s=d]'), function (fld) {
                            if (fld.disabled || !fld.name) { return; }
                            var __ty = (fld.type || '').toLowerCase();
                            if ((__ty === 'checkbox' || __ty === 'radio') && !fld.checked) { return; }
                            if (fld.tagName === 'SELECT' && fld.multiple) {
                                Array.prototype.forEach.call(fld.selectedOptions, function (o) { __p.append(fld.name, o.value); });
                                return;
                            }
                            __p.append(fld.name, fld.value);
                        });
                        var __body = new URLSearchParams();
                        __body.set('d', __p.toString());
                        __body.set('ui', '" . $options['destUi'] . "');
                        __body.set('pc', '" . $options['pc'] . "');
                        __body.set('ip', '" . $options['idParent'] . "');
                        __body.set('je', '" . $options['jsElement'] . "');
                        __body.set('jet', '" . $options['jsElementType'] . "');
                        __body.set('dialog', '" . $options['dialog'] . "');
                        __body.set('tp', '" . $options['tp'] . "');
                        fetch(_SITE_URL + '" . $name . "/update', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
                            body: __body.toString()
                        }).then(function (r) { return r.text(); }).then(function (html) {
                            var __d = document.getElementById('" . $options['destUi'] . "');
                            if (!__d) { return; }
                            // Mirror jQuery .append(html): insert markup AND execute
                            // any returned <script> (the /update response is a
                            // <script> body — sw_message on success / alertb on
                            // validation failure). innerHTML alone never runs scripts.
                            var __tmp = document.createElement('div');
                            __tmp.innerHTML = html;
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
                })();";
            } else {
                $editEvent = "(function(){ var __b = document.querySelector('#form" . $name . " #save" . $name . "'); if(__b){ __b.addEventListener('click', (data)=>{" . $options['onSave'] . "}); } })();";
            }
        }

        return form(
            div(
                $content
                . $formSaveBar
                , "divCnt$name", "class='divStdform'")
            , "id='form$name' class='mainForm formContent' ")
        . scriptReady($editEvent);
    }
}
