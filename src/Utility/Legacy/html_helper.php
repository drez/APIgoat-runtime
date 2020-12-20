<?php


function clean($string, $more = false)
{
    $string = str_replace("\'", "'", $string);
    $string = str_replace("'", "''", $string);
    if ($more)
        $string = str_replace("\"", "”", $string);
    return $string;
}

function htmlLink($name, $link, $options = "", $title = "")
{
    if (!empty($title)) {
        if ($title === true) {
            $title = ' title="' . strip_tags($name) . '" ';
        } else {
            $title = ' title="' . $title . '" ';
        }
    }

    $optionsContent = generateData($options);

    return "<a href=\"$link\" $optionsContent $title>$name</a>";
}

function href($name, $link, $options = "", $title = "")
{
    return htmlLink($name, $link, $options, $title);
}

function div($content, $id = "", $options = "")
{
    if ($id) $id = "id=\"$id\"";
    $optionsContent = generateData($options);

    return "<div $id $optionsContent>$content</div>";
}

function pre($content, $options = "")
{
    return "<pre $options>$content</pre>";
}

function input($type, $name, $value = "", $options = "", $id = "")
{
    if ($id == "") {
        $id = $name;
    }

    $optionsContent = generateData($options);

    return "<input type=\"$type\" name=\"$name\" id=\"$id\" value=\"" . $value . "\" $optionsContent/>";
}

function img($path, $height = "", $width = "", $options = "", $alt = "", $title = "")
{
    $optionsContent = generateData($options);

    if (!empty($other))
        $other = ' ' . $other;
    /* bug au niveau explorer il voyais pas les photo maintenant corrigé avec cette patch */
    if (!empty($height)) {
        if ($height === true) {
            $path = _SITE_URL . 'img/' . $path;
        } else {
            $height =  ' height="' . $height . '" ';
        }
    }

    if (!empty($width))
        $width = ' width="' . $width . '" ';

    if (!empty($alt)) {
        if ($title == '') {
            $title = ' title="' . $alt . '" ';
        } else {
            $title = ' title="' . $title . '" ';
        }

        $alt = ' alt="' . $alt . '" ';
    }

    return "<img src=\"" . $path . "\" $width  $height $optionsContent $alt $title />";
}

function p($content, $options = "")
{
    $optionsContent = generateData($options);

    return "<p $optionsContent>$content</p>";
}

function b($content, $options = "")
{
    return "<b $options>$content</b>";
}
function strong($content, $options = "")
{
    $optionsContent = generateData($options);

    return "<strong $optionsContent>$content</strong>";
}
function i($content, $options = "")
{
    return "<i $options>$content</i>";
}

function h1($content, $options = "")
{
    $optionsContent = generateData($options);

    return "<h1 $optionsContent>$content</h1>";
}

function h2($content, $options = "")
{
    $optionsContent = generateData($options);

    return "<h2 $optionsContent>$content</h2>";
}

function h3($content, $options = "")
{
    $optionsContent = generateData($options);

    return "<h3 $optionsContent>$content</h3>";
}

function h4($content, $options = "")
{
    $optionsContent = generateData($options);

    return "<h4 $optionsContent>$content</h4>";
}

function ul($content, $options = "")
{
    $optionsContent = generateData($options);

    return "<ul $optionsContent>$content</ul>";
}

function li($content, $options = "")
{
    $optionsContent = generateData($options);

    return "<li $optionsContent>$content</li>";
}

function span($content = "", $options = "")
{
    $optionsContent = generateData($options);

    return "<span $optionsContent>$content</span>";
}

function tr($content, $options = "")
{
    $optionsContent = generateData($options);

    return "<tr $optionsContent>$content</tr>\n";
}

function td($content, $options = "")
{
    $optionsContent = generateData($options);

    return "<td $optionsContent>$content</td>";
}

function th($content, $options = "")
{
    $optionsContent = generateData($options);

    return "<th $optionsContent>$content</th>";
}

function thead($content, $option = "")
{
    return "<thead $option>$content</thead>";
}

function body($content, $option = "")
{
    return "<body $option>
$content</body>";
}

function tbody($content, $option = "")
{
    return "<tbody $option>$content</tbody>";
}

function tfoot($content, $option = "")
{
    return "<tfoot $option>$content</tfoot>";
}

function table($content, $option = "")
{
    return "<table $option>$content</table>";
}

function form($content, $option = "")
{
    return "<form $option>$content</form>";
}

function select($name, $options, $selOption = "", $idSelected = "", $id = "", $opt_re = false, $null_in_sel = false)
{
    if (empty($id))
        $id = $name;
    if ($null_in_sel) {
        $null_in_sel_tab = array('0' => _MESS_SELECTION, '1' => '');
        if ($options) {
            array_unshift($options, $null_in_sel_tab);
        } else {
            $options = array($null_in_sel_tab);
        }
    }
    if (is_array($options)) {
        for ($i = 0, $c = count($options); $i < $c; $i++) {
            if (!empty($options[$i][0])) {
                // handle multiple selected id
                if (is_array($idSelected)) {
                    if (array_search($options[$i][1], $idSelected) !== false) {
                        $option .= option($options[$i][0], $options[$i][1], "v='" . $options[$i][2] . "' selected=\"yes\"");
                    } else
                        $option .= option($options[$i][0], $options[$i][1], "v='" . $options[$i][2] . "'");
                } else {
                    // handle standard selected id
                    if ($idSelected == $options[$i][1]) {
                        $option .= option($options[$i][0], $options[$i][1], "v='" . $options[$i][2] . "' selected=\"yes\"");
                    } else
                        $option .= option($options[$i][0], $options[$i][1], "v='" . $options[$i][2] . "'");
                }
            }
        }
        $options = $option;
    }
    if (!$opt_re)
        return "<select name=\"$name\" id=\"$id\" $selOption>$options</select>";
    else
        return $options;
}

function selectbox($name, $select, $valeur = '', $class = '')
{
    $placeholder = $name;
    if ($valeur) {
        $name = $valeur;
    }
    return label(span($name, ' placeholder="' . $placeholder . '" class="select-label-span"') . $select, '  class="select-label js-select-label ' . $class . '"');
}

function optionListeSelect($options, $selectedValue, $defaultLabel = true)
{
    if ($options) {
        $selectedLabel = "";
        foreach ($options as $option) {
            $class = "";
            if (is_array($selectedValue)) {
                if (array_search($option[1], $selectedValue) !== false) {
                    $class = ' class="selected"';
                    $selectedLabel .= $option[0] . ',';
                }
            } else {
                if ($selectedValue == $option[1]) {
                    $class = ' class="selected"';
                    $selectedLabel .= $option[0] . ',';
                }
            }
            $optionsList .=
                li(
                    strong(ucfirst($option[0]), 'unselectable="on" title="' . ucfirst($option[0]) . '"'),
                    'v=\'' . $option[2] . '\' unselectable="on" data-label="' . $option[0] . '" data-value="' . $option[1] . '"' . $class
                );
        }
    }
    if (empty($selectedLabel)) {
        if (!empty($defaultLabel)) {
            $selectedLabel = $defaultLabel;
        } else {
            /* mets rien esti */
            /* $selectedLabel = $options[0][0];
            $inputValue = $options[0][1];*/
        }
    } else {
        $selectedLabel = substr($selectedLabel, 0, -1);
    }
    $return['optionsList'] = $optionsList;
    $return['selectedLabel'] = $selectedLabel;
    return $return;
}

function selectboxCustomArray($name, $options, $defaultLabel = '', $attr = '', $selectedValue = 'default', $classParam = '', $null_in_sel = false)
{
    $placeholder = $name;
    $tabindex = "";
    $multiple = false;
    if (is_array($selectedValue)) {
        $selectedValue = implode(',', $selectedValue);
    }
    $inputValue = $selectedValue;
    if (!is_array($selectedValue)) {
        $selectedValue = explode(',', $selectedValue);
    }
    if ($selectedValue) {
        foreach ($selectedValue as $value) {
            $valuesList[] = $value;
        }
    }

    $attr = str_replace('otherTabs=1', '', $attr);
    if (!preg_match('/disabled/', $attr))
        $tabindex = ' tabindex="0" othertabs="1"';
    if (preg_match('/multiple/', $attr))
        $multiple = true;

    $rOption = optionListeSelect($options, $selectedValue, $defaultLabel);
    $optionsList = $rOption['optionsList'];
    $selectedLabel = $rOption['selectedLabel'];

    if (!empty($defaultLabel)) {
        $defaultLabel = li(span(_('Clear'), 'unselectable="on" title="' . ucfirst($defaultLabel) . '"'), 'class="default" unselectable="on" data-label="' . $defaultLabel . '" data-value="default"');
    }
    if ($null_in_sel) {
        $emptyLabel = li(span(_('Empty value'), 'unselectable="off" title="' . ucfirst(_('Empty value')) . '"'), 'class="null" unselectable="off" data-label="' . _('Empty value') . '" data-value="_null"');
        $classParam .= " hasNull";
    }
    $grayClass = " gray ";
    if ($inputValue) {
        $grayClass = "";
    }
    $mobileHeader = "";
    if ($multiple) {
        $mobileHeader = div(span('x', 'class="select-close-button js-select-close-button"'), '', 'class="mobile-header"');
    }
    $findme = 'SearchTabs';
    $SearchTabs = " SearchTabs='1' ";
    if (strpos($attr, $findme) === false) {
        $SearchTabs = '';
    }
    $list = ul(
        $defaultLabel
            . $emptyLabel
            . $optionsList,
        'class="scrollable select-element ' . $name . '" data-default-selected=\'' . json_encode($valuesList) . '\''
    )
        . input('hidden', $name, $inputValue, 'class="selextbox-input NC' . str_replace('[]', '', $name) . '"  ' . $SearchTabs . ' s="d"');

    return label(
        span($selectedLabel, ' placeholder="' . $placeholder . '"  class="select-label-span' . $grayClass . '"' . $tabindex) . $mobileHeader . $list,
        str_replace('SearchTabs=\'1\'', '', str_replace('s=\'d\'', '', $attr . ' data-child-select="' . str_replace('[]', '', $name) . '" data-name="' . $name . '" class="select-label js-select-label ' . $classParam . '"'))
    );
}

function arrayToOptions($options, $idSelected = '')
{
    if (is_array($options)) {
        for ($i = 0, $c = count($options); $i < $c; $i++) {
            if (!empty($options[$i][0])) {
                // handle multiple selected id
                if (is_array($idSelected)) {
                    if (array_search($options[$i][1], $idSelected)) {
                        $option .= option($options[$i][0], $options[$i][1], "v='" . $options[$i][2] . "' selected=\"yes\"");
                    } else
                        $option .= option($options[$i][0], $options[$i][1], "v='" . $options[$i][2] . "'");
                } else {
                    // handle standard selected id
                    if ($idSelected == $options[$i][1]) {
                        $option .= option($options[$i][0], $options[$i][1], "v='" . $options[$i][2] . "' selected=\"yes\"");
                    } else
                        $option .= option($options[$i][0], $options[$i][1], "v='" . $options[$i][2] . "'");
                }
            }
        }
        $options = $option;
    }
    return $options;
}

function arrayToJson($options, $idSelected = '')
{
    if (is_array($options)) {
        for ($i = 0, $c = count($options); $i < $c; $i++) {
            if (!empty($options[$i][0])) {
                // handle multiple selected id
                if (is_array($idSelected)) {
                    if (array_search($options[$i][1], $idSelected)) {
                        $option[$options[$i][1]] = li($options[$i][0], "data-label='" . $options[$i][0] . "' data-value='" . $options[$i][1] . "' v='" . $options[$i][2] . "' class='selected'");
                    } else
                        $option[$options[$i][1]] = li($options[$i][0], "data-label='" . $options[$i][0] . "' data-value='" . $options[$i][1] . "' v='" . $options[$i][2] . "'");
                } else {
                    // handle standard selected id
                    if ($idSelected == $options[$i][1]) {
                        $option[$options[$i][1]] = li($options[$i][0], "data-label='" . $options[$i][0] . "' data-value='" . $options[$i][1] . "' v='" . $options[$i][2] . "' class='selected'");
                    } else
                        $option[$options[$i][1]] = li($options[$i][0], "data-label='" . $options[$i][0] . "' data-value='" . $options[$i][1] . "' v='" . $options[$i][2] . "'");
                }
            }
        }
    }
    return json_encode($option);
}

function option($caption, $value, $options = "")
{
    return "<option value=\"$value\" $options>$caption</option>";
}

function iframe($src, $options = "")
{
    return "<iframe src=\"$src\" $options></iframe>
";
}

function textarea($id, $value = "", $options = "")
{
    $optionsContent = generateData($options);

    return "<textarea id=\"$id\" name=\"$id\" $optionsContent >$value</textarea>";
}

function customCheckInput($input, $label)
{
    return span(
        $input
            . span('', 'class="placeholder-input"')
            . span(
                $label,
                'class="checkbox-label"'
            ),
        'class="custom-input"'
    );
}
function checkbox($id, $value, $options = "")
{
    return "<input type=\"checkbox\" id=\"$id\" name=\"$id\" value=\"$value\" $options>";
}
function radio($id, $value, $options = "")
{
    return "<input type=\"radio\" id=\"$id\" name=\"$id\" value=\"$value\" $options>";
}

function loadCss($style, $options = "")
{
    if ($style) {
        return "<link href=\"$style\" rel=\"stylesheet\" type=\"text/css\" $options />";
    }
}

function loadjs($js)
{
    if ($js) {
        return "<script src=\"$js\" type=\"text/javascript\"></script>";
    }
}

function htmlHeader($title = "", $style = "", $desciption = "", $keywords = "", $others = "", $favicon = "favicon.ico", $author = '')
{
    $Html_head = "<head>";
    //$Html_head .= "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=ISO-8859-1\" />";
    $Html_head .= "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />";
    if (!empty($desciption))
        $Html_head .= "<meta name=\"description\" content=\"$desciption\" />";
    if (!empty($keywords))
        $Html_head .= "<meta name=\"keywords\" content=\"$keywords\" />";
    if (!empty($title))
        $Html_head .= "<title>$title</title>";
    if (!empty($favicon))
        $Html_head .= "<link rel=\"icon\" type=\"image/png\" href=\"" . _SITE_URL . $favicon . "\" />";
    if (!empty($style))
        $Html_head .= $style;
    $Html_head .= $others;
    $Html_head .= "<meta name='Author' content='" . $author . "' />";
    $Html_head .= "<meta name='viewport' content='width=device-width, initial-scale=1, maximum-scale=1'>";

    $Html_head .= "</head>";
    return $Html_head;
}

function docType()
{
    //return "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">";
    //return "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.01//EN\" \"http://www.w3.org/TR/html4/strict.dtd\">";
    return "<!DOCTYPE HTML>";
}

function htmlTag($content, $option = "")
{
    $Html = "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"fr\" lang=\"fr\" dir=\"ltr\"  " . $option . ">";
    $Html .= $content;
    $Html .= "</html>";
    return $Html;
}

function startHtml()
{
    $Html = "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"fr\" lang=\"fr\" dir=\"ltr\">";
    return $Html;
}

function closeHtml()
{
    $Html_foot .= "</html>";
    return $Html_foot;
}

function htmlSpace($nbr)
{
    for ($i = 0; $i < $nbr; $i++)
        $space .= "&nbsp;";
    return $space;
}

function req($val)
{
    return str_replace('\\', '\\\\', strip_tags(trim(htmlspecialchars((get_magic_quotes_gpc() ? stripslashes($_REQUEST[$val]) : $_REQUEST[$val]), ENT_QUOTES))));
}

function cleanString($str)
{
    $str = str_replace("script", "", $str);
    return $str;
}

function getUrlParamsJSON($arrayParams = "", $asUrl = false)
{
    $keys = array_keys($_REQUEST);

    if ($asUrl) {
        foreach ($keys as $key) {
            if (in_array($key, $arrayParams)) {
                $urlParams .= "&" . $key . "=" . urlencode($_REQUEST[$key]);
            }
        }
        return $urlParams;
    }

    $urlParams .= "'dum':'z'";
    if ($arrayParams[0] != "") {
        foreach ($keys as $key) {
            if (in_array($key, $arrayParams)) {
                $urlParams .= ",\"" . $key . "\":\"" . urlencode($_REQUEST[$key]) . "\"";
            }
        }
    } else {
        foreach ($keys as $key) {
            if ($key != "__utma" && $key != "__utmz" && $key != "PHPSESSID" && !strstr($key, "SESS") && $key != "dum") {
                $urlParams .= ",'" . $key . "':'" . urlencode($_REQUEST[$key]) . "'";
            }
        }
    }
    return $urlParams;
}

function message($message)
{
    return "<script>$(document).ready(function() {message('" . $message . "');});</script>";
}

function script($data, $option = "")
{
    return "<script type='text/javascript' " . $option . ">" . $data . "</script>";
}

function scriptReady($data, $option = "")
{
    return "<script type='text/javascript' " . $option . ">
$(document).ready(function(){
    " . $data . "
});</script>";
}

function style($data, $option = "")
{
    return "<style type='text/css' $option>" . trim(preg_replace("/\s+/", " ", $data)) . "</style>";
}


function createRandomKey($amount, $options = [])
{
    $keyset = "abcdefghijklmnopqrstuvwxyz";
    if ($options['capital'] != false) {
        $keyset .= "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    }
    if ($options['number'] != false) {
        $keyset .= "0123456789";
    }
    if ($options['special'] == true) {
        $keyset .= "!@#$%^&*()_+=-<>";
    }

    $randkey = "";
    for ($i = 0; $i < $amount; $i++)
        $randkey .= substr($keyset, rand(0, strlen($keyset) - 1), 1);
    return $randkey;
}

function ynToStr($yn, $lang = 'fr')
{
    if ($lang == 'fr') {
        return ($yn == 'y') ? "Oui" : "Non";
    }
    if ($lang == 'en') {
        return ($yn == 'y') ? "Yes" : "No";
    }
}

function selectYN($name, $selected = '', $options = '')
{
    return select($name, array(0 => array('Yes', 'y'), 1 => array('No', 'n')), $options, $selected);
}

function selectYesNo($name, $selected = '', $options = '')
{
    return select($name, array(0 => array('Yes', 'yes'), 1 => array('No', 'no')), $options, $selected);
}

function selectIntYN($name, $selected = '', $options = '', $null = false)
{
    if ($null)
        $choices = array(0 => array('Choose', '0'), 1 => array('Yes', '1'), 2 => array('No', '2'));
    else
        $choices = array(0 => array('Yes', '1'), 1 => array('No', '0'));
    return select($name, $choices, $options . " s='" . $selected . "'", $selected);
}

function selectLang($name, $selected = '', $options = '')
{
    return select($name, array(0 => array('Francais', 'FR'), 1 => array('English', 'EN')), $options, $selected);
}
function assocToNumDef($array, $addDefault = false, $valeur = _MESS_SELECTION)
{
    $arrValues = array_values($array);
    $len = count($arrValues);
    /*if($addDefault){
        $num[] = array(1=> NULL, 0=> $valeur);
    }*/
    for ($i = 0; $i < $len; $i++) {
        $val =  array_values($arrValues[$i]);
        $num[] = $val;
    }
    return $num;
}

function assocToNumWidthNull($array, $addDefault = false)
{
    return assocToNumDef($array, $addDefault = false);
}

function assocToNum($array, $addDefault = false)
{
    $arrValues = array_values($array);
    $len = count($arrValues);
    /* if($addDefault){
        $num[] = array(1=> NULL, 0=> _MESS_SELECTION, 2=>'_MESS_SELECTION');
    }*/
    for ($i = 0; $i < $len; $i++) {
        $val =  array_values($arrValues[$i]);
        $num[] = $val;
    }
    return $num;
}
function assocToNumV($array, $addDefault = false)
{
    $arrValues = array_values($array);
    $len = count($arrValues);
    /*if($addDefault){
        $num[] = array(1=> NULL, 0=> _MESS_SELECTION, 2=>'_MESS_SELECTION');
    }*/
    for ($i = 0; $i < $len; $i++) {
        $val =  array_values($arrValues[$i]);
        $num[] = $val;
    }
    return $num;
}
$array_search;
function message_label($label, $local = NULL)
{

    if ($label) {
        global $messagesCache;
        if (!$local)
            $local = $_SESSION[_AUTH_VAR]->config['locale']['locale'];

        if (!isset($messagesCache[$label][$local])) {
            $data = \App\MessageQuery::create()
                ->filterByLabel($label)
                ->findOne();

            if ($data) {
                $messagesCache[$label][$local] = $data->getTranslation($local)->getText();
            }
        }

        if (empty($data)) {
            $e = new App\Message();
            $e->setLabel($label);
            $e->save();

            if (count($_SESSION[_AUTH_VAR]->config['locale']['supported_locale']) > 1) {
                foreach ($_SESSION[_AUTH_VAR]->config['locale']['supported_locale'] as $locale) {
                    $mt = new App\MessageI18n();
                    $mt->setLocale($locale);
                    $mt->setText($label == '' ? null : $label);
                    $e->addMessageI18n($mt);
                    $e->save();
                }
            } else {
                $mt = new App\MessageI18n();
                $mt->setLocale($local);
                $mt->setText($label == '' ? null : $label);
                $e->addMessageI18n($mt);
                $e->save();
            }

            $messagesCache[$label][$local] = $label;
        }

        return $messagesCache[$label][$local];
    }
}

function handleNotOkResponse($msg, $ui = '', $print = false, $text_title = 'Message')
{
    $ui = (!empty($ui)) ? '#' . $ui : '';
    $msg = message_label($msg);
    $error['txt'] .= $msg;

    if ($_SESSION[_AUTH_VAR]->SessVar['content-type'] == 'JSON') {
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        header('Content-type: application/json');
        $ret['status'] = 'error-notok';
        $ret['data']['title'] = $text_title;
        $ret['data']['msg'] = $msg;
        die(json_encode($ret));
    } else {
        if ($print) {
            $error['onReadyJs'] = "
            <script>$('#ui-dialog-title-alertDialog').html('" . str_replace("'", " ", $text_title) . "');
            $('#alert_text').show().html('" . str_replace("'", " ", $msg) . "');
            $('#alertDialog').dialog('open');</script>

        ";
        } else {
            $error['onReadyJs'] = "
            $('#ui-dialog-title-alertDialog').html('" . str_replace("'", " ", $text_title) . "');
            $('#alert_text').show().html('" . str_replace("'", " ", $msg) . "');
            $('#alertDialog').dialog('open');";
            $error['error'] = 'yes';
        }

        return $error;
    }
}
function handleValidationError($objValidationFails, $ui = '', $text_title = 'Message', $extValidationErr = '')
{
    $error['error'] = 'yes';
    $red_flag = "";
    $fields = array();
    if (is_array($extValidationErr)) {
        foreach ($extValidationErr as $failure => $field) {
            $msg = message_label($failure);
            $error['txt'] .= $msg . "<br>";
            $fields = array_merge($fields, $field['fields']);
        }
    }
    foreach ($objValidationFails->getValidationFailures() as $failure) {
        $msg = message_label($failure->getMessage());
        $error['txt'] .= $msg . "<br>";
        $fields[] = $failure->getColumn();
    }
    $ui = (!empty($ui)) ? "#" . $ui : "";
    $error['onReadyJs'] .= "
    $('" . $ui . " .error_field').removeClass('error_field');";
    foreach ($fields as $field) {
        if (!empty($field)) {
            if (strstr($field, '.')) {
                $input = explode('.', $field);
                $fieldName = $input[1];
            } else
                $fieldName = $field;
            $error['onReadyJs'] .= "
                if($('" . $ui . " [v=" . addslashes(strtoupper($fieldName)) . "] .select-label-span').length > 0){
                     $('" . $ui . " [v=" . addslashes(strtoupper($fieldName)) . "] .select-label-span').addClass('error_field');
                }else{
                     $('" . $ui . " [v=" . addslashes(strtoupper($fieldName)) . "]').addClass('error_field');
                }
            ";
        }
    }
    $error['onReadyJs'] .= "

        $('#ui-dialog-title-alertDialog').html('" . addslashes($text_title) . "');
        $('#alert_text').show().html('" . addslashes($error['txt']) . "');
        $('#alertDialog').dialog('open');
        alert_close = '$(\'" . $ui . " .error_field\').first().focus();';
    ";

    if ($_SESSION[_AUTH_VAR]->SessVar['content-type'] == 'JSON') {
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        header('Content-type: application/json');
        $ret['status'] = 'error-validation';
        $ret['data'] = $fields;
        die(json_encode($ret));
    } else {
        return $error;
    }
}
function camelize($string, $pascalCase = false)
{
    if (strpos($string, '_') !== false) {
        $string = strtolower($string);
    }
    $string = str_replace(array('-', '_'), ' ', $string);
    $string = ucwords($string);
    $string = str_replace(' ', '', $string);

    return $string;
}
function unCamelize($input)
{
    preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
    $ret = $matches[0];
    foreach ($ret as &$match) {
        $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
    }
    return implode('_', $ret);
}
function format_phone($phone)
{
    return format_phone_v2($phone);
}
function format_phone_v2($phone)
{
    $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

    if (strlen($phoneNumber) > 10) {
        $countryCode = substr($phoneNumber, 0, strlen($phoneNumber) - 10);
        $areaCode = substr($phoneNumber, -10, 3);
        $nextThree = substr($phoneNumber, -7, 3);
        $lastFour = substr($phoneNumber, -4, 4);

        $phoneNumber = '+' . $countryCode . ' (' . $areaCode . ') ' . $nextThree . '-' . $lastFour;
    } else if (strlen($phoneNumber) == 10) {
        $areaCode = substr($phoneNumber, 0, 3);
        $nextThree = substr($phoneNumber, 3, 3);
        $lastFour = substr($phoneNumber, 6, 4);

        $phoneNumber = '(' . $areaCode . ') ' . $nextThree . '-' . $lastFour;
    } else if (strlen($phoneNumber) == 7) {
        $nextThree = substr($phoneNumber, 0, 3);
        $lastFour = substr($phoneNumber, 3, 4);

        $phoneNumber = $nextThree . '-' . $lastFour;
    }

    return ltrim($phoneNumber, '0');
}

function return_jour($id)
{
    $tab_jour[0] = "Samedi";
    $tab_jour[1] = "Dimanche";
    $tab_jour[2] = "Lundi";
    $tab_jour[3] = "Mardi";
    $tab_jour[4] = "Mercredi";
    $tab_jour[5] = "Jeudi";
    $tab_jour[6] = "Vendredi";

    return substr($tab_jour[$id], 0, 3);
}

function label($text, $options = "")
{
    return "<label $options>$text</label>";
}

function article($text, $options = "")
{
    return "<article $options>$text</article>";
}
function button($text, $options = "")
{
    return "<button $options>$text</button>";
}

function section($text, $options = "")
{
    $optionsContent = generateData($options);

    return "<section $optionsContent>$text</section>";
}
function canvas($text, $options = "")
{
    return "<canvas $options>$text</canvas>";
}

function headerTag($text, $options = "")
{
    return "<header $options>$text</header>";
}

function nav($text, $options = "")
{
    $optionsContent = generateData($options);

    return "<nav $optionsContent>$text</nav>";
}

function footer($text, $options = "")
{
    return "<footer $options>$text</footer>";
}

function encrypt_decrypt($action, $string)
{
    $output = false;

    $encrypt_method = "AES-256-CBC";
    $secret_key = _CRYPT_KEY;
    $secret_iv = _CRYPT_IV;

    $key = hash('sha256', $secret_key);
    $iv = substr(hash('sha256', $secret_iv), 0, 16);

    if ($action == 'encrypt') {
        $output = openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
        $output = base64_encode($output);
    } else if ($action == 'decrypt') {
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
    }

    return $output;
}

function generateData($options)
{
    if (is_array($options)) {
        if ($options[0] === null) {
            $dataArray = $options;
        } else {
            $dataArray = $options[1];
            $optionsContent .= $options[0];
        }

        if (count($dataArray) > 0) {
            foreach ($dataArray as $key => $data) {
                $optionsContent .= 'data-' . $key . '="' . $data . '" ';
            }
        }
    } else {
        $optionsContent = $options;
    }

    return $optionsContent;
}

function send404()
{
    if ($_SESSION[_AUTH_VAR]->SessVar['content-type'] == 'JSON') {
        http_response_code(404);
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        header('Content-type: application/json');
        $ret['status'] = 'error-notfound';
        die(json_encode($ret));
    } else {
        http_response_code(404);
    }
}

function swheader($name = '', $hasControls = '')
{
    return div(
        htmlLink(span(_('Open/close menu')), 'javascript:', 'class="toggle-menu button-link-blue trigger-menu"')
            . div($controlsContent, $name . 'ControlsList', "class='custom-controls " . $hasControls . "'")
            . $_SESSION['ccSwCustom'],
        '',
        'class="sw-header"'
    );
}
