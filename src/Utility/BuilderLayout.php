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
        $headjs = "<script type='text/javascript'>
    let _SITE_URL = '" . addslashes(_SITE_URL) . "';
</script>";

        // PWA meta tags and icons for iOS / Android / Windows
        $pwaHeaders = '
<style>html{background-color:#ffffff}</style>
<link rel="manifest" href="' . _SITE_URL . 'manifest.webmanifest">
<link rel="apple-touch-icon" sizes="180x180" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<link rel="apple-touch-icon" sizes="152x152" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<link rel="apple-touch-icon" sizes="167x167" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<link rel="apple-touch-icon" sizes="120x120" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<link rel="icon" type="image/png" sizes="512x512" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<link rel="icon" type="image/png" sizes="192x192" href="' . _SITE_URL . 'public/img/fav-2.1.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="AExpert">
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
        if (empty($content['html'])) {
            return "Response is empty, does the service exists?";
        }

        $pageLoader = '<div id="pageLoader" style="position:fixed;top:0;left:0;width:100%;height:100%;background:#667eea;z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:opacity 0.3s ease;">
<img src="' . _SITE_URL . 'public/img/ios/512.png" style="width:80px;height:80px;border-radius:18px;box-shadow:0 4px 20px rgba(0,0,0,0.25);margin-bottom:18px;" alt="">
<div style="width:48px;height:48px;border:4px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:pl-spin 0.8s linear infinite;"></div>
</div>
<style>@keyframes pl-spin{to{transform:rotate(360deg)}}</style>
<script>
window.addEventListener("load",function(){var l=document.getElementById("pageLoader");if(l){l.style.opacity="0";setTimeout(function(){l.style.display="none";document.documentElement.style.backgroundColor="";},300);}});
if("serviceWorker"in navigator&&navigator.serviceWorker.controller){navigator.serviceWorker.addEventListener("message",function(e){if(e.data&&e.data.type==="SW_AUTH_RELOAD")window.location.reload();});}
</script>';

        $leftPannel = '';
        $pannelStylesOverride = '';

        if($this->showLeftPannel){
             $leftPannel = div(
            div(
                div(
                    div(
                        //href(img($logoAdmin),_SITE_URL,'class="logo-wrapper"')
                        $this->getTopNav(),
                        '',
                        'class="top-nav"'
                    )
                    . nav($this->builderMenus->getMenus(), 'class="ac-nav"'),
                    '',
                    'class="left-panel-content" '
                ),
                '',
                'class="left-panel-wrapper" '
            ),
            '',
            'class="left-panel" '
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
                . div(
                    div(div($content['html'], 'tabsContain'), '', 'class="content-wrapper"')
                    . div('', 'editPane', 'class="edit-pane-hidden"'),
                    '',
                    'class="center-panel" '.$pannelStylesOverride
                )

                . div('', 'editDialog', 'style=""')
                . div('', 'editPopupDialog', 'style="d" ')
                . div(
                    div(p('', "id='confirm_text'"), '', "class='mainForm'"),
                    'confirmDialog'
                )
                . div(
                    div(p('', "id='alert_text'"), '', "class='mainForm'"),
                    'alertDialog'
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

        return ul(
            li(href(img(_SITE_URL . vendor_logo), vendor_url, 'class="logo-wrapper"'))
            . li(href(span(_("Home")), _SITE_URL, 'title="Home" class="icon home"'), "class='right'")
            . $items
            . li(href(span(_("Menu")), "Javascript:void(0);", 'title="Menu" class="icon menu trigger-menu"')),
            'class="nav"'
        );
    }

    public function renderOpen($content)
    {
    }

    public function renderDownload($content, $name)
    {
        if ($content) {
            return $content;
        } else {
            return "Error";
        }
        /*header("Content-disposition: attachment; filename=".$name."");
        header("Content-Type: application/force-download");
        header("Content-Transfer-Encoding: $type\n"); // Surtout ne pas enlever le \n
        header("Pragma: no-cache");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0, public");
        header("Expires: 0");*/
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
                $editEvent = "$('#form" . $name . " #save" . $name . "').bindSave({
                                    modelName: '" . $name . "',
                                    destUi: '" . $options['destUi'] . "',
                                    pc:'" . $options['pc'] . "',
                                    ip:'" . $options['idParent'] . "',
                                    je:'" . $options['jsElement'] . "',
                                    jet:'" . $options['jsElementType'] . "',
                                    tp:'" . $options['tp'] . "',
                                    dialog:'" . $options['dialog'] . "'
                                });";
            } else {
                $editEvent = "$('#form" . $name . " #save" . $name . "').bind('click.save$name', (data)=>{" . $options['onSave'] . "});";
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
