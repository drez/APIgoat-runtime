<?php


namespace ApiGoat\Services;

use ApiGoat\Services\Service;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ApiGoat\Api\ApiResponse;


class GuiManager extends Service
{

    public $body;
    
    public function __construct(Request $request, Response $response, array $args)
    {
        parent::__construct($request, $response, $args);
        $this->set();
    }


    public function getApiResponse()
    {
        $ApiResponse = new ApiResponse($this->args, $this->response, $this->body);
        return $ApiResponse->getResponse();
    }

    public function set()
    {
        $this->body['status'] = 'failure';

        $p = $this->args['p'];
        $act = $this->args['act'];
        $i = $this->args['i'];
        $a = $this->args['a'];
        $ms = $this->args['ms'];
        $d = $this->args['d'];
        $ogf = $this->args['ogf'];
        $v = $this->args['v'];
        $nomem = $this->args['nomem'];
        $Autoc = $this->args['who'];
        $h = $this->args['h'];

        if ($a == 'ixsamem') {
            \AuthyQuery::create()
                ->filterByIdAuthy($_SESSION[_AUTH_VAR]->get('id'))
                ->update(array('Onglet' => serialize($_SESSION['memoire'])));
            $this->body['status'] = 'success';
        }
        if ($a == 'ixiconel') {
            $_SESSION['mem']['onglet']['vl'] = $v;
            $this->body['status'] = 'success';
        }
        if ($a == 'ixiconer') {
            $_SESSION['mem']['onglet']['vr'] = $v;
            $this->body['status'] = 'success';
        }
        if ($a == 'ixiconet') {
            $_SESSION['mem']['onglet']['vt'] = $v;
            $this->body['status'] = 'success';
        }
        if ($a == 'ixogf') {
            if ($p and  $ogf) {
                $_SESSION['mem']['onglet'][$p]['ogf'] = $ogf;
            }
            $this->body['status'] = 'success';
        }
        if ($a == 'ixmem') {
            if ($d and $p) {
                $_SESSION['mem']['onglet'][$p]['mem'] = $d;
            }
            $this->body['status'] = 'success';
        }

        if ($a == 'ixmemautoc') {
            if ($p and $Autoc) {
                $_SESSION['mem']['onglet'][$p]['ixmemautoc'] = $Autoc;
            }
            $this->body['status'] = 'success';
        }
        /* pour la suppresion des menu */
        if ($a == 'ixkill') {
            if ($p == 'delfull') {
                unset($_SESSION['mem']['onglet']);
                unset($_SESSION['mem']['search']);
            }
            if ($p and $i == 'all' and $act == 'edit') {
                unset($_SESSION['mem']['onglet'][$p]);
            } else if ($p and $i != '' and $act == 'edit') {
                $_SESSION['mem']['onglet'][$p]['i'] = rmv_var($_SESSION['mem']['onglet'][$p]['i'], $i, ',', false);
                if (!$_SESSION['mem']['onglet'][$p]['i']) {
                    unset($_SESSION['mem']['onglet'][$p]['edit']);
                    unset($_SESSION['mem']['onglet'][$p]['para']['edit'][$i]);
                }
            } else if ($p and !$i and $act == 'list') {
                unset($_SESSION['mem']['onglet'][$p]);
                if ($_SESSION['mem']['onglet']['current'] == $p) {
                    unset($_SESSION['mem']['onglet']['current']);
                }
                unset($_SESSION['mem']['search']);
            }
            \AuthyQuery::create()
                ->filterByIdAuthy($_SESSION[_AUTH_VAR]->get('id'))->update(array('Onglet' => serialize($_SESSION['memoire'])));
            $this->body['status'] = 'success';
        }
    }
}
