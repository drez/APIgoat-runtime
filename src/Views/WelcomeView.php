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

        // App state card (always first when present)
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

        // One card per Category
        foreach ($categoryBuckets as $category => $rows) {
            $cardBody = '';
            foreach ($rows as $Config) {
                $cardBody .= div(
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
            $cards .= div(
                div(
                    div(div(htmlspecialchars($category), '', "class='nav-title'"), '', "class='form-nav'"),
                    '', "class='sw-header'"
                ) . div($cardBody, '', "class='sw-body'"),
                '', "class='sw-drawer proto-form'"
            );
        }

        // API security card (last, only when $hasAPI)
        if ($hasAPI) {
            $cards .= div(
                div(
                    div(div(_('API security'), '', "class='nav-title'"), '', "class='form-nav'"),
                    '', "class='sw-header'"
                ) . div(
                    div(
                        div(_("Before using the API, please make yourself familiar with:"))
                            . ul(
                                li(_("the ") . "<a target='_doc' href='https://apigoat.com/docs/your-app/api-acl/'>" . _("Rule Based Access List") . "</a>")
                                    . li(_("the ") . "<a target='_doc' href='https://apigoat.com/docs/your-app/permissions/'>" . _("App Permissions") . "</a>")
                                    . li(_("the ") . "<a target='_doc' href='https://apigoat.com/docs/api/rest-api-basics/'>" . _("Overall query/response dynamic") . "</a>")
                            ),
                        '', "class='form-row'"
                    ),
                    '', "class='sw-body'"
                ),
                '', "class='sw-drawer proto-form'"
            );
        }

        $return['html'] = swheader() . div(
            $greeting . div($cards, '', "class='welcome-stack'"),
            '',
            "class='proto-screen welcome-screen'"
        );

        $return['onReadyJs'] = "
    $('[ag_save=Config]').bind('change', function (){
        var config = $(this).attr('config');
        var value = 'dev';
        if(config == 'app_status'){
            if($(this).prop( 'checked')){
                value = 'prod';
            }
            $(this).siblings('#Value').val(value);
        }
        var id = $(this).siblings('#IdConfig').val();
        $.post(_SITE_URL+'Config/update/'+id, { 
            d: $(this).parents('#form_'+config).find('[ag_save=Config]').serialize(), ui: 'tabsContain', jet:'swWarn'
         }, function (response){
            $('#tabsContain').append(response);
        });
    });
        ";
        return $return;
    }
}
