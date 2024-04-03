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

    public function dashboard()
    {

        $Configs = \App\ConfigQuery::create()
            ->orderBy('Category', 'ASC')
            ->find();

        $hasAPI = false;
        $app_status_checked = '';
        $curCategory = '';
        $configForm = '';
        foreach ($Configs as $Config) {
            if ($Config->getConfig() == 'app_status') {
                $app_status_checked = ($Config->getValue() == 'prod') ? 'checked=true' : '';
                $app_status_id = $Config->getIdConfig();
            } else {
                if ($Config->getConfig() == 'api_ips') {
                    $hasAPI = true;
                }

                if (empty($curCategory) || $curCategory != $Config->getCategory()) {
                    $configForm .= h2($Config->getCategory());
                    $curCategory = $Config->getCategory();
                }

                $configForm .= div(
                    form(
                        label($Config->getConfig())
                            . div(
                                input('text', 'Value', \htmlentities($Config->getValue()), "config='" . $Config->getConfig() . "' ag_save='Config'")
                                    . input('hidden', 'IdConfig', $Config->getIdConfig(), "ag_save='Config'"),
                                '',
                                "class='divtd '"
                            )
                            . div($Config->getDescription(), '', "class='explain'"),
                        "id='form_" . $Config->getConfig() . "'"
                    ),
                    '',
                    "class='divtr '"
                );
            }
        }

        if ($hasAPI) {
            $ApiSection =
                h1("API security")
                . div(
                    div(
                        h4("Before using the API, please make yourself familiar with:")
                            . ul(
                                li("the <a target='_doc' href='https://apigoat.com/docs/your-app/api-acl/'>Rule Based Access List</a>")
                                    . li("the <a target='_doc' href='https://apigoat.com/docs/your-app/permissions/'>App Permissions</a>")
                                    . li("the <a target='_doc' href='https://apigoat.com/docs/api/rest-api-basics/'>Overall query/response dynamic</a>")
                            ),
                        '',
                        "class='divtd'"
                    ),
                    '',
                    "class='divtr colspan4'"
                );
        }

        $return['html'] = swheader() . div(
            h1("Welcome to your APIgoat App Control panel")
                . div("This is the default welcome page for your App. You can replace it by adding HTML to the editor. Take a look at the 
                parameters below, they control some key aspect of your App.", '', "class='paragraph'")
                .
                h1("App configs")
                . div(
                    form(
                        label("Current state of the App.")
                            . div(
                                input('checkbox', 'config', 'app_status', $app_status_checked . " config='app_status' ag_save='Config'")
                                    . label('Development / Production', "for='config'")
                                    . input('hidden', 'IdConfig', $app_status_id, "ag_save='Config'")
                                    . input('hidden', 'Value', '', "ag_save='Config'"),
                                '',
                                "class='divtd '"
                            )
                            . div("Developement version will be slower. It will produce logs and allow unknowned API access.", '', "class='explain'"),
                        "id='form_app_status'"
                    ),
                    '',
                    "class='divtr'"
                ) . $configForm
                . $ApiSection,
            '',
            "class='mainForm form' style='padding-bottom:50px;'"
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
