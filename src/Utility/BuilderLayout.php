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


    function __construct(BuilderMenus $BuilderMenus)
    {
        include _BASE_DIR . 'config/assets.php';
        $Config = new Configuration(require _BASE_DIR . 'config/settings.php');
        $this->settings = $Config->getArray('admin_panel');
        $siteDescription = '';
        $siteKeywords = '';
        $favicon = '';
        $headAuthor = '';
        $this->builderMenus = $BuilderMenus;

        $this->incCss = $Assets->css() . $AssetsAdmin->css();
        if (defined('_TITLE_PREFIX')) {
            $this->title = _TITLE_PREFIX . " / " . $siteTitle;
        }
        $headjs = "<script type='text/javascript'>
    let _SITE_URL = '" . addslashes(_SITE_URL) . "';
</script>";

        $this->htmlHeader = htmlHeader($this->title, $this->incCss, $siteDescription, $siteKeywords, $headjs . $AssetsHead->js() . $AssetsAdmin->js() . $Assets->js(), $favicon, $headAuthor);


        return $this;
    }

    public function setTitle($title)
    {
        $this->title = $title;
    }

    public function renderXHR($content)
    {
        if (!empty($content['html']) || !empty($content['js']) || !empty($content['onReadyJs'])) {
            return $content['html'] . $content['js']
                . scriptReady(trim($content['onReadyJs']));
        } else {
            if (!empty($content)) {
                return $content;
            }
        }
        return "The response is empty.";
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

    public function render($content)
    {
        if (empty($content['html'])) {
            return "Response is empty, does the service exists?";
        }

        $output = [];
        $body = [];
        $authy = '';

        $print =
            docType()
            . htmlTag(
                $this->htmlHeader
                . body(
                    $body['html']
                    . script($body['js'])
                    . div(
                        div(
                            div(
                                div(
                                    //href(img($logoAdmin),_SITE_URL,'class="logo-wrapper"')
                                    $this->getTopNav()
                                    . $authy,
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
                    )
                    . div(
                        div(div($content['html'], 'tabsContain'), '', 'class="content-wrapper"')
                        . div('', 'editPane', 'class="edit-pane-hidden"')
                        . $output['pagerRow'],
                        '',
                        'class="center-panel"'
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

                    . $this->js
                    . $output['EndBody'],
                    " id='body' class='" . (isset($bodyClass) ? $bodyClass : '') . "' style='height:100%;'"
                ),
                " id='html_build' "
            )
            . $content['js']
            . scriptReady(trim($content['onReadyJs']));

        return $print;
    }

    public function getTopNav()
    {

        $menus = ['profil', 'support', 'dashboard'];
        $items = '';
        $settings = $this->settings['top_nav'];
        foreach ($menus as $menu) {
            if (isset([$menu]['url'])) {
                $items .= li(href(span(_($settings[$menu]['caption'])), $settings[$menu]['url'], 'title="' . $settings[$menu]['title'] . '" class="icon ' . $menu . '"'), "class='right'");
            }
        }

        return ul(
            li(href(img(vendor_logo), vendor_url, 'class="logo-wrapper"'))
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
                $head = div(h3('Warning'), '', "class='box-header'");
                $contentClass = 'box';
                $bodyClass = 'bodybg';
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
}