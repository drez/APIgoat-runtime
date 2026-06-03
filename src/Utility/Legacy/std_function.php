<?php


#@0.1@#########################
#	Legacy functions
# to be removed 
###############################

function slugify($string)
{
    $patterns[0] = '/[á|â|à|å|ä]/';
    $patterns[1] = '/[ð|é|ê|è|ë]/';
    $patterns[2] = '/[í|î|ì|ï]/';
    $patterns[3] = '/[ó|ô|ò|ø|õ|ö]/';
    $patterns[4] = '/[ú|û|ù|ü]/';
    $patterns[6] = '/ç/';
    $replacements[0] = 'a';
    $replacements[1] = 'e';
    $replacements[2] = 'i';
    $replacements[3] = 'o';
    $replacements[4] = 'u';
    $replacements[6] = 'c';

    $string = preg_replace($patterns, $replacements, $string);
    $string = preg_replace('~[^-\w]+~', '', strtolower(iconv('utf-8', 'us-ascii//TRANSLIT', trim(preg_replace('~[^\pL\d]+~u', '-', $string), '-'))));
    return str_replace(array('----', '---', '--'), '-', $string);
}

function security_redirect($redirect = true, $request = [])
{
    if (function_exists('beforeSecurityRedirect')) {
        beforeSecurityRedirect();
    }
    $log = true;
    //automat_connect();

    if ($_SESSION[_AUTH_VAR]->SessVar['content-type'] == 'JSON') {
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        header('Content-type: application/json');
        $ret['status'] = 'error';
        $ret['data'] = "session expired";
        die(json_encode($ret));
    } else {
        if ($redirect) {
            $jsRedirect = script("setTimeout(function (){ window.location.href = '" . _SITE_URL . _ADMIN_HOME_URL . "' }, 1000);");
        }
        if ($log) {
            die(docType()
                . htmlTag(
                    htmlHeader($request['p'] . "-" . $request['a'], $css . $uiCss . loadCss(_SITE_URL . 'mod/page/template_css.css'), _SITE_DESCRIPTION, _SITE_KEYWORDS, $headJs)
                        . div(div(_("Session expired"), '', "class='expired-session-msg'"), '', "class='expired-session-msg-ctnr'")
                        . $jsRedirect
                        . style(".expired-session-msg-ctnr{width:100%;padding-top:100px;background-color:#F0F0F0;border: 1px solid #d1d1d1;}
    .expired-session-msg{margin:auto;font-size: 20px;text-align: center;padding:20px;height: calc( 100% - 120px);}")
                ));
        }
    }
}

function en_de($action, $string)
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

function rmv_var($stringIn, $rmv_string, $sep, $space = true)
{
    $stringOut = "";
    foreach (explode($sep, $stringIn) as $val) {
        if ($rmv_string != $val) {
            if ($space) {
                if ($stringOut) {
                    $stringOut .= " " . $sep . " " . $val;
                } else {
                    $stringOut = " " . $val;
                }
            } else {
                if ($stringOut) {
                    $stringOut .= "" . $sep . "" . $val;
                } else {
                    $stringOut = "" . $val;
                }
            }
        }
    }
    return $stringOut;
}

function serializeRights($data, $fieldName)
{
    require _BASE_DIR . "config/permissions.php";
    foreach ($omMap as $right) {
        if ($data[$fieldName . '-' . $right['name'] . 'r'] == 'r')
            $arrayRights[$right['name']] .= "r";
        if ($data[$fieldName . '-' . $right['name'] . 'w'] == 'w')
            $arrayRights[$right['name']] .= "w";
        if ($data[$fieldName . '-' . $right['name'] . 'a'] == 'a')
            $arrayRights[$right['name']] .= "a";
        if ($data[$fieldName . '-' . $right['name'] . 'd'] == 'd')
            $arrayRights[$right['name']] .= "d";
    }
    return json_encode($arrayRights);
}

function isntPo($str)
{
    if (!is_null($str)) {
        return _($str);
    } else {
        return NULL;
    }
}

function string2url($in)
{
    $in = html_entity_decode($in, ENT_QUOTES, 'UTF-8');
    $in = urldecode($in);
    $in = trim(strip_tags($in));
    $in = preg_replace('/[^a-z\d]+/i', '-', $in);

    $ret = strtolower(transliterateString($in));
    return $ret;
}

function transliterateString($txt)
{
    $transliterationTable = array('á' => 'a', 'Á' => 'A', 'à' => 'a', 'À' => 'A', 'ă' => 'a', 'Ă' => 'A', 'â' => 'a', 'Â' => 'A', 'å' => 'a', 'Å' => 'A', 'ã' => 'a', 'Ã' => 'A', 'ą' => 'a', 'Ą' => 'A', 'ā' => 'a', 'Ā' => 'A', 'ä' => 'ae', 'Ä' => 'AE', 'æ' => 'ae', 'Æ' => 'AE', 'ḃ' => 'b', 'Ḃ' => 'B', 'ć' => 'c', 'Ć' => 'C', 'ĉ' => 'c', 'Ĉ' => 'C', 'č' => 'c', 'Č' => 'C', 'ċ' => 'c', 'Ċ' => 'C', 'ç' => 'c', 'Ç' => 'C', 'ď' => 'd', 'Ď' => 'D', 'ḋ' => 'd', 'Ḋ' => 'D', 'đ' => 'd', 'Đ' => 'D', 'ð' => 'dh', 'Ð' => 'Dh', 'é' => 'e', 'É' => 'E', 'è' => 'e', 'È' => 'E', 'ĕ' => 'e', 'Ĕ' => 'E', 'ê' => 'e', 'Ê' => 'E', 'ě' => 'e', 'Ě' => 'E', 'ë' => 'e', 'Ë' => 'E', 'ė' => 'e', 'Ė' => 'E', 'ę' => 'e', 'Ę' => 'E', 'ē' => 'e', 'Ē' => 'E', 'ḟ' => 'f', 'Ḟ' => 'F', 'ƒ' => 'f', 'Ƒ' => 'F', 'ğ' => 'g', 'Ğ' => 'G', 'ĝ' => 'g', 'Ĝ' => 'G', 'ġ' => 'g', 'Ġ' => 'G', 'ģ' => 'g', 'Ģ' => 'G', 'ĥ' => 'h', 'Ĥ' => 'H', 'ħ' => 'h', 'Ħ' => 'H', 'í' => 'i', 'Í' => 'I', 'ì' => 'i', 'Ì' => 'I', 'î' => 'i', 'Î' => 'I', 'ï' => 'i', 'Ï' => 'I', 'ĩ' => 'i', 'Ĩ' => 'I', 'į' => 'i', 'Į' => 'I', 'ī' => 'i', 'Ī' => 'I', 'ĵ' => 'j', 'Ĵ' => 'J', 'ķ' => 'k', 'Ķ' => 'K', 'ĺ' => 'l', 'Ĺ' => 'L', 'ľ' => 'l', 'Ľ' => 'L', 'ļ' => 'l', 'Ļ' => 'L', 'ł' => 'l', 'Ł' => 'L', 'ṁ' => 'm', 'Ṁ' => 'M', 'ń' => 'n', 'Ń' => 'N', 'ň' => 'n', 'Ň' => 'N', 'ñ' => 'n', 'Ñ' => 'N', 'ņ' => 'n', 'Ņ' => 'N', 'ó' => 'o', 'Ó' => 'O', 'ò' => 'o', 'Ò' => 'O', 'ô' => 'o', 'Ô' => 'O', 'ő' => 'o', 'Ő' => 'O', 'õ' => 'o', 'Õ' => 'O', 'ø' => 'oe', 'Ø' => 'OE', 'ō' => 'o', 'Ō' => 'O', 'ơ' => 'o', 'Ơ' => 'O', 'ö' => 'oe', 'Ö' => 'OE', 'ṗ' => 'p', 'Ṗ' => 'P', 'ŕ' => 'r', 'Ŕ' => 'R', 'ř' => 'r', 'Ř' => 'R', 'ŗ' => 'r', 'Ŗ' => 'R', 'ś' => 's', 'Ś' => 'S', 'ŝ' => 's', 'Ŝ' => 'S', 'š' => 's', 'Š' => 'S', 'ṡ' => 's', 'Ṡ' => 'S', 'ş' => 's', 'Ş' => 'S', 'ș' => 's', 'Ș' => 'S', 'ß' => 'SS', 'ť' => 't', 'Ť' => 'T', 'ṫ' => 't', 'Ṫ' => 'T', 'ţ' => 't', 'Ţ' => 'T', 'ț' => 't', 'Ț' => 'T', 'ŧ' => 't', 'Ŧ' => 'T', 'ú' => 'u', 'Ú' => 'U', 'ù' => 'u', 'Ù' => 'U', 'ŭ' => 'u', 'Ŭ' => 'U', 'û' => 'u', 'Û' => 'U', 'ů' => 'u', 'Ů' => 'U', 'ű' => 'u', 'Ű' => 'U', 'ũ' => 'u', 'Ũ' => 'U', 'ų' => 'u', 'Ų' => 'U', 'ū' => 'u', 'Ū' => 'U', 'ư' => 'u', 'Ư' => 'U', 'ü' => 'ue', 'Ü' => 'UE', 'ẃ' => 'w', 'Ẃ' => 'W', 'ẁ' => 'w', 'Ẁ' => 'W', 'ŵ' => 'w', 'Ŵ' => 'W', 'ẅ' => 'w', 'Ẅ' => 'W', 'ý' => 'y', 'Ý' => 'Y', 'ỳ' => 'y', 'Ỳ' => 'Y', 'ŷ' => 'y', 'Ŷ' => 'Y', 'ÿ' => 'y', 'Ÿ' => 'Y', 'ź' => 'z', 'Ź' => 'Z', 'ž' => 'z', 'Ž' => 'Z', 'ż' => 'z', 'Ż' => 'Z', 'þ' => 'th', 'Þ' => 'Th', 'µ' => 'u', 'а' => 'a', 'А' => 'a', 'б' => 'b', 'Б' => 'b', 'в' => 'v', 'В' => 'v', 'г' => 'g', 'Г' => 'g', 'д' => 'd', 'Д' => 'd', 'е' => 'e', 'Е' => 'E', 'ё' => 'e', 'Ё' => 'E', 'ж' => 'zh', 'Ж' => 'zh', 'з' => 'z', 'З' => 'z', 'и' => 'i', 'И' => 'i', 'й' => 'j', 'Й' => 'j', 'к' => 'k', 'К' => 'k', 'л' => 'l', 'Л' => 'l', 'м' => 'm', 'М' => 'm', 'н' => 'n', 'Н' => 'n', 'о' => 'o', 'О' => 'o', 'п' => 'p', 'П' => 'p', 'р' => 'r', 'Р' => 'r', 'с' => 's', 'С' => 's', 'т' => 't', 'Т' => 't', 'у' => 'u', 'У' => 'u', 'ф' => 'f', 'Ф' => 'f', 'х' => 'h', 'Х' => 'h', 'ц' => 'c', 'Ц' => 'c', 'ч' => 'ch', 'Ч' => 'ch', 'ш' => 'sh', 'Ш' => 'sh', 'щ' => 'sch', 'Щ' => 'sch', 'ъ' => '', 'Ъ' => '', 'ы' => 'y', 'Ы' => 'y', 'ь' => '', 'Ь' => '', 'э' => 'e', 'Э' => 'e', 'ю' => 'ju', 'Ю' => 'ju', 'я' => 'ja', 'Я' => 'ja');
    return str_replace(array_keys($transliterationTable), array_values($transliterationTable), $txt);
}

function sendHTMLemail($message, $from, $to, $subject, $reply = "", $attachment = array(), $bcc = "")
{
    /*if(!eregi("^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,4})$", $to)){
        return "Error: misformated recipient email value
";
    }*/
    $Email_boundary = "" . md5(uniqid()) . "";
    $mimeType = "multipart/alternative;";
    $Email_boundaryAlt = "alt-" . $Email_boundary;
    $Email_boundaryMain = $Email_boundaryAlt;
    $EmailBoundaryMixedHead = "";
    $eol = "
";

    if ($attachment['name']) {
        $mimeType = "multipart/mixed;";
        $Email_boundaryMixed = "mix-" . $Email_boundary;
        $Email_boundaryMain = $Email_boundaryMixed;
        $EmailBoundaryMixedHead = "--" . $Email_boundaryMixed . $eol . "Content-Type: multipart/alternative; boundary=\"" . $Email_boundaryAlt . "\"" . $eol . $eol;
    }

    $additional_headers = 'X-Mailer: PHP/sendmail' . $eol;
    $additional_headers .= 'MIME-Version: 1.0' . $eol;
    $additional_headers .= "Content-Type: " . $mimeType . " boundary=\"" . $Email_boundaryMain . "\"" . $eol;
    if ($reply) {
        $additional_headers .= "Reply-To: " . $reply . $eol;
    }
    if ($bcc) {
        $additional_headers .= "Bcc: " . $bcc . $eol;
    }
    $additional_headers .= "From: " . $from . $eol;

    $htmlMessage = docType() . startHtml() . body($message) . closeHtml();

    $multipartEmail =
        $EmailBoundaryMixedHead
        . "--" . $Email_boundaryAlt . $eol
        . 'Content-Type: text/plain; charset="UTF-8"' . $eol
        . 'Content-Transfer-Encoding: 7bit' . $eol . $eol
        . htmlspecialchars_decode(utf8_decode(strip_tags($htmlMessage))) . $eol . $eol
        . "--" . $Email_boundaryAlt . "
Content-Type: text/html; charset='UTF-8'
Content-Transfer-Encoding: base64" . $eol . $eol
        . chunk_split(base64_encode($htmlMessage)) . $eol . $eol
        . "--" . $Email_boundaryAlt . "--";

    if ($attachment['name']) {
        $multipartEmail .= $eol . "
--" . $Email_boundaryMixed . "
Content-Type: " . $attachment['mime'] . "; name=\"" . $attachment['name'] . "\"
Content-Transfer-Encoding: base64
Content-Disposition: attachment" . $eol . $eol;
        $multipartEmail .= chunk_split(base64_encode($attachment['data'])) . $eol . $eol . "--" . $Email_boundaryMixed . "--";
    }
    $add_header = "";
    if ($from) {
        $add_header = "-f " . $from;
    }
    if (strstr($to, ";")) {
        $recipient = explode(";", $to);
        foreach ($recipient as $to) {
            $return = mail($to, $subject, $multipartEmail, $additional_headers, $add_header);
        }
    } else {
        $return =  mail($to, $subject, $multipartEmail, $additional_headers, $add_header);
    }

    if ($return)
        return true;
    else
        return false;
}

function handleOkResponse($msg, $ui = '', $print = false, $text_title = 'Message')
{
    $ui = (!empty($ui)) ? '#' . $ui : '';
    //$msg = message_label($msg);
    $error['txt'] .= $msg;
    $error['onReadyJs'] = "
        document.body.style.cursor = 'progress';
        document.querySelectorAll('input').forEach(function(el){ el.style.cursor = 'progress'; });
        setTimeout(function (){ document.body.style.cursor = 'auto'; document.querySelectorAll('input').forEach(function(el){ el.style.cursor = 'pointer'; }); },200);
    ";
    return $error;
}

function isMobile()
{
    return preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $_SERVER["HTTP_USER_AGENT"]);
}

// @FORMFIELD
function stdFieldRow($label, $input, $name = '', $formUnit = '', $comments = '', $comments_css = '', $addClass = '', $options = '', $isCheckbox = 'no', $variant = '')
{
    $checkboxLabel = '';
    $hasUnit = '';
    if ($formUnit != '') {
        $hasUnit = 'has-unit';
    }

    if ($isCheckbox == 'yes') {
        $checkboxLabel = label('', "for='" . $name . "'");
    }

    // v2 = guideline mobile edit-form row: <div class="form-row">
    //   <span class="lbl">Label</span> <input …></div>. No divtr/divtd/
    //   label[for] grid; _formv2.scss styles .form-row inside .form-card.
    if ($variant === 'v2') {
        return
            div(
                span($label, "class='lbl'")
                    . $input . $formUnit . $comments . $checkboxLabel,
                "",
                " class='form-row " . $comments_css . $addClass . "' " . $options . " "
            );
    }

    return
        div(
            label($label, "for='" . $name . "'")
                . div($input . $formUnit . $comments . $checkboxLabel, "", " class='divtd " . $hasUnit . "'  in='in" . $name . "'"),
            "",
            " class='divtr " . $comments_css . $addClass . "' " . $options . " "
        );
}

if (!function_exists('array_column')) {
    function array_column($array, $column_name)
    {
        return array_map(function ($element) use ($column_name) {
            return $element[$column_name];
        }, $array);
    }
}
function create_preview($text, $qty = 100)
{
    $text = html_entity_decode(strip_tags($text), ENT_COMPAT, 'UTF-8');
    if (strlen($text) > $qty) {
        $lastWord = strpos($text, ' ', $qty);
        if ($lastWord) {
            $text = substr($text, 0, $lastWord);
            $last_character = (substr($text, -1, 1));
            $text = $last_character == '.' ? $text : $text . '...';
        }
    }
    return $text;
}

function wp_postlogin()
{
    define('WP_USE_THEMES', false);
    require_once("../wp-load.php");
    $hf_user = wp_get_current_user();
    $hf_username = $hf_user->user_login;
    if ($hf_username) {
        $Authy = AuthyQuery::create()
            ->filterByWpUser($hf_username)
            ->findOne();
        if ($Authy) {
            $e = new AuthyForm();
            $e->setSession($Authy, $Authy->getUsername());
        }
    }
}

function preprint($str)
{
    echo "<pre>" . print_r($str, true) . "</pre>";
}

function mres($value)
{
    $search = array('\\',  '\x00', '\n',  '\r',  "'",  '"', '\x1a');
    $replace = array('\\\\', '\\0', '\\n', '\\r', "\'", '\"', '\\Z');

    return str_replace($search, $replace, $value);
}
