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

        // Every one of these is an OPTIONAL query parameter — the GUI sends
        // the two or three its current action needs, never all eleven — so
        // reading them unguarded emitted ~10 "Undefined array key" warnings
        // per request into php-error.log on every page render. '' rather
        // than null keeps the loose comparisons below ($a == 'alive',
        // $i != '') behaving exactly as they did for a present-but-empty
        // parameter.
        $p = $this->args['p'] ?? '';
        $act = $this->args['act'] ?? '';
        $i = $this->args['i'] ?? '';
        $a = $this->args['a'] ?? '';
        $ms = $this->args['ms'] ?? '';
        $d = $this->args['d'] ?? '';
        $ogf = $this->args['ogf'] ?? '';
        $v = $this->args['v'] ?? '';
        $nomem = $this->args['nomem'] ?? '';
        $Autoc = $this->args['who'] ?? '';
        $h = $this->args['h'] ?? '';

        if ($a == 'alive') {
            $this->body['status'] = 'success';
        }

        if ($a == 'ixsamem') {
            // Persisting IS this action: report success only when it landed.
            $this->body['status'] = $this->persistOnglet() ? 'success' : 'failure';
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

            // The session-side kill above is what the GUI needs; the DB copy
            // is best-effort (a failure is logged by persistOnglet()).
            $this->persistOnglet();
            $this->body['status'] = 'success';
        }

        if ($a == 'login') {
            $timezone = \App\Domains\Timezone\Timezone::detect_timezone_id($this->args['t']['offset'], $this->args['t']['dst']);
            if($timezone){
                date_default_timezone_set($timezone);
                $_SESSION[_AUTH_VAR]->sessVar['Timezone'] = $timezone;
            }
            $this->body['status'] = 'success';
            $this->body['body'] = ['timezone' => $timezone];
        }
    }

    /**
     * Save the tab memory ($_SESSION['mem']) onto the caller's own Authy row.
     *
     * The update goes through AuthyQuery, so the ORM's tenant / ACL scoping
     * applies. A row scoped out of the caller's reach used to surface as an
     * exception (older Propel BasePeer) and now as a 0-row UPDATE. MySQL also
     * reports 0 affected rows when the stored value is unchanged, so a 0 is
     * confirmed with a scoped count before being treated as a miss. Never
     * throws: the tab state is a convenience, not worth a 500.
     */
    private function persistOnglet(): bool
    {
        $id = $_SESSION[_AUTH_VAR]->get('id');
        try {
            $n = \App\AuthyQuery::create()
                ->filterByIdAuthy($id)
                ->update(array('Onglet' => serialize($_SESSION['mem'] ?? [])));
            if ($n > 0 || \App\AuthyQuery::create()->filterByIdAuthy($id)->count() > 0) {
                return true;
            }
            error_log('ApiGoat GuiManager: tab memory not saved, Authy ' . (int) $id . ' is out of scope');
        } catch (\Exception $x) {
            error_log('ApiGoat GuiManager: tab memory not saved for Authy ' . (int) $id . ': ' . $x->getMessage());
        }
        return false;
    }
}
