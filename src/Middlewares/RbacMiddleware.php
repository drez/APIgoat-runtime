<?php

namespace ApiGoat\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use ApiGoat\Api\ApiResponse;
use App\ApiRbacQuery;
use Selective\Config\Configuration;

/**
 * Description of RbacMiddleware
 * Renamed API ACL
 *
 * @author sysadmin
 */
class RbacMiddleware implements MiddlewareInterface
{
    private $args;
    public $rbac_is_new = false;
    private $rbac_id;
    private $config;
    private $rbac_rule;
    private $rbac_role;
    private $response;
    private $raw_parameters;
    # Declared explicitly so PHP 8.4 doesn't emit a dynamic-property deprecation.
    private $prettyBody;


    public function __construct(ResponseFactoryInterface $responseFactory = null)
    {
        $Configuration = new Configuration(require _BASE_DIR . 'config/settings.php');
        $this->config = $Configuration->getArray('rbac');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $error = [];
        $this->args = $request->getAttribute('parsed_args');
        $this->raw_parameters = $request->getUri()->getQuery();
        if (empty($this->raw_parameters) && isset($this->args['data']['query'])) {
            $this->raw_parameters = json_encode($this->args['data']['query']);
        }
        $rawBody = (string)$request->getBody();
        $request->getBody()->rewind();
        if ($rawBody) {
            $this->raw_parameters = $this->raw_parameters
                ? $this->raw_parameters . '&body=' . rawurlencode($rawBody)
                : $rawBody;
        }
        if (strstr($request->getUri()->getPath(), '/api/v') && $this->args['method'] != 'OPTIONS') {

            if ($request->getAttribute('rbac_public') === 'failed') {
                // second pass for private route
                $this->rbac_rule = $request->getAttribute('rbac_rule');
                $error = $this->authorizePrivateRequest($request->getAttribute('rbac_id'));
            } elseif ($request->getAttribute('rbac_complete') != 'yes') {
                // first pass for public route
                $private = $this->authorizePublicRequest();
                $request = $request->withAttribute('normalized_query', ((isset($this->args['data']['query']) ? $this->args['data']['query'] : null)));
                $request = $request->withAttribute('rbac_id', $this->rbac_id);

                if ($private === false) {
                    $request = $request->withAttribute('rbac_public', 'passed');
                    $this->args['rbac_public'] = 'passed';
                    $request = $request->withAttribute('rbac_complete', 'yes');
                } else {
                    $request = $request->withAttribute('rbac_public', 'failed');
                    $this->args['rbac_public'] = 'failed';
                    $request = $request->withAttribute('rbac_complete', 'no');
                    $request = $request->withAttribute('rbac_rule', $this->rbac_rule);
                }
            }
        }

        if ($this->rbac_rule == 'Deny') {
            $error[] = "Route denied. Check your API access control. Hard 'Deny'.";
        }

        if ($error) {
            $ApiResponse = new ApiResponse($this->args, $this->response, ['status' => 'failure', 'data' => null, 'errors' => $error]);
            return $ApiResponse->setStatus(401)->getResponse();
        }

        $response = $handler->handle($request);
        return $response;
    }

    private function authorizePrivateRequest($rbac_id = null)
    {
        $idAuthy = null;
        if ($_SESSION[_AUTH_VAR]->get('connected') == 'YES') {
            $idAuthy = $_SESSION[_AUTH_VAR]->getIdAuthy();
            if ((!empty($this->rbac_role) && $this->rbac_role != $_SESSION[_AUTH_VAR]->SessVar['IdRole']) || $this->rbac_rule == 'Deny') {
                $this->logApi($rbac_id, $idAuthy);
                return ['Route denied. Check your API access control.'];
            }
        } else {
            $this->logApi($rbac_id, null);
            return ['Route denied, private route require authentication.'];
        }

        $ApiRbac = ApiRbacQuery::create()->findPk($rbac_id);
        if ($ApiRbac) {
            $ApiRbac->setCount($ApiRbac->getCount() + 1);
            $this->saveBookkeeping($ApiRbac);
            $this->logApi($rbac_id, $idAuthy);
        }


        return false;
    }

    /**
     * Search the RBAC database for a match or log a new entry and deny
     *
     * @return bool
     */
    private function authorizePublicRequest()
    {
        // process body
        if ($this->args['data'] == '' || $this->args['data'] == null) {
            $q = \App\ApiRbacQuery::create()
                ->filterByModel($this->args['model'])
                ->filterByAction($this->args['action'])
                ->filterByMethod($this->args['method']);
            $q->filterByBody('')->_or()->filterByBody(null)
                ->orderBy('DateCreation', 'ASC');
            $ApiRbac = $q->findOne();
            $wildBody[0] = null;
        } else {
            // check for some wildcard
            $this->normalizeFilter($this->args['data']);
            $this->excludeBody();
            // rbac excludes can clear the body; fall back to model/action/method + empty body match
            if ($this->args['data'] === null || $this->args['data'] === '' || (is_array($this->args['data']) && $this->args['data'] === [])) {
                $q = \App\ApiRbacQuery::create()
                    ->filterByModel($this->args['model'])
                    ->filterByAction($this->args['action'])
                    ->filterByMethod($this->args['method']);
                $q->filterByBody('')->_or()->filterByBody(null)
                    ->orderBy('DateCreation', 'ASC');
                $ApiRbac = $q->findOne();
                $wildBody[0] = null;
            } else {
                $bestMatch = $this->findBestMatch();

                if ($bestMatch) {
                    $ApiRbac = \App\ApiRbacQuery::create()->findPk($bestMatch);
                }
                //get the wildcard rule
                $bodyData = is_array($this->args['data'])
                    ? $this->args['data']
                    : (isset($this->args['raw']) ? json_decode($this->args['raw'], true) : null);
                $wildBody = $this->getBodyWildcarded($bodyData);
            }
        }

        if (!$ApiRbac) {
            // add a new rule with default values
            $ApiRbac = new \App\ApiRbac();
            $body = (isset($wildBody[2]) ? $wildBody[2] : (($wildBody[1]) ? $wildBody[1] : $wildBody[0]));
            $ApiRbac->setModel($this->args['model']);
            $ApiRbac->setAction($this->args['action']);
            $ApiRbac->setMethod($this->args['method']);
            $default_rule = (\defined('app_status') && \app_status == 'dev') ? 'Allow' : 'Deny';
            $ApiRbac->setRule($default_rule);
            $ApiRbac->setBody(((\is_null($body)) ? null : \json_encode(\json_decode($body, true), \JSON_PRETTY_PRINT)));
            $ApiRbac->setCount(1);
            $this->rbac_id = $this->saveBookkeeping($ApiRbac);
            $this->rbac_rule = $default_rule;
            $this->rbac_role = null;
            $this->rbac_is_new = true;
            $this->logApi($this->rbac_id);
            if (\defined('app_status') && \app_status == 'dev') {
                return false;
            }
            return true;
        } elseif ($ApiRbac->getScope() == 'Public' && $ApiRbac->getRule() != 'Deny') {
            // pass public route
            $ApiRbac->setCount($ApiRbac->getCount() + 1);
            $this->rbac_id = $this->saveBookkeeping($ApiRbac);
            $this->logApi($this->rbac_id);
            return false;
        } else {
            // failed
            $this->rbac_rule = $ApiRbac->getRule();
            $this->rbac_role = null;
            $this->rbac_id = $ApiRbac->getPrimaryKey();
            if (\defined('app_status') && \app_status == 'dev') {
                $ApiRbac->setCount($ApiRbac->getCount() + 1);
                $this->saveBookkeeping($ApiRbac);
                // Keep private routes on the private-auth pass in dev mode too.
                return true;
            }
            return true;
        }
    }

    private function logApi($IdApiRbac, $IdAuthy = null)
    {
        $ApiLog = new \App\ApiLog();
        $ApiLog->setIdApiRbac($IdApiRbac);
        $ApiLog->setIdAuthy($IdAuthy);
        $ApiLog->setTime(time());
        $ApiLog->setRawParameters($this->raw_parameters);
        $this->saveBookkeeping($ApiLog);
    }

    /**
     * Persist a non-essential RBAC/audit row. Route auto-discovery, hit
     * counts and api-log entries are bookkeeping; if the write fails — e.g.
     * the session points at a since-deleted authy user (DB reseed, dropped
     * account), so the add_tablestamp id_creation/id_modification stamp
     * violates the authy FK — log a warning and let the user's actual
     * request proceed rather than surfacing a 500.
     *
     * @return mixed primary key on success, null on failure
     */
    private function saveBookkeeping($obj)
    {
        try {
            $obj->save();
            return method_exists($obj, 'getPrimaryKey') ? $obj->getPrimaryKey() : true;
        } catch (\Exception $e) {
            \Propel::log('RBAC/audit bookkeeping write skipped (request continues): ' . $e->getMessage(), \Propel::LOG_WARNING);
            return null;
        }
    }

    function excludeBody()
    {
        if (is_array($this->config['excludes'])) {
            foreach ($this->config['excludes'] as $body => $exclude) {
                if (
                    ($exclude['method'] == '*' || $exclude['method'] == $this->args['method'])
                    && ($exclude['model'] == '*' || $exclude['model'] == $this->args['model'])
                    && ($exclude['action'] == '*' || $exclude['action'] == $this->args['action'])
                ) {
                    unset($this->args['data']);
                    $this->args['data'] = null;
                }
            }
        }
    }

    function normalizeFilter()
    {
        if (is_array($this->args['data']['query']['filter'])) {
            $normalized = [];
            foreach ($this->args['data']['query']['filter'] as $model => $filters) {

                if (\is_numeric($model)) {
                    $model = $this->args['model'];
                    if (is_array($filters)) {
                        $normalized[$model][] = $filters;
                    } else {
                    }
                } else {
                    $normalized[$model] = $filters;
                }
            }
            $this->args['data']['query']['filter'] = $normalized;
        }
    }

    function findBestMatch()
    {

        $i = 0;
        $select = [];
        $where = [];
        $fields = [];
        $params = [];
        if (is_array($this->args['data'])) {
            foreach ($this->args['data'] as $key => $val) {
                if ($key == 'query') {
                    if (!empty($this->args['data']['query']['select'])) {
                        $path = 'query.select';
                        $pathPh = ":p{$i}";
                        $jsonPh = ":j{$i}";
                        $params[$pathPh] = '$.' . $path;
                        $params[$jsonPh] = json_encode($this->args['data']['query']['select']);
                        $select[] = "IF(JSON_CONTAINS(`body`, {$jsonPh}, {$pathPh}), 1, 0) as `m{$i}`";
                        $where[] = "(JSON_CONTAINS(`body`,  {$jsonPh}, {$pathPh})
                             OR JSON_VALUE(`body`, {$pathPh}) = '*')
            ";
                        $fields[] = "m{$i}";
                        $i++;
                    }


                    if (is_array($this->args['data']['query']['filter'])) {
                        foreach ($this->args['data']['query']['filter'] as $model => $filters) {

                            if (is_array($filters)) {
                                foreach ($filters as $val) {
                                    $path = "query.filter." . $model;
                                    $pathPh = ":p{$i}";
                                    $jsonPh = ":j{$i}";
                                    $starPh = ":js{$i}";
                                    $params[$pathPh] = '$.' . $path;
                                    $params[$jsonPh] = '[' . json_encode($val) . ']';
                                    $params[$starPh] = '[' . json_encode([($val[0] ?? null), '*']) . ']';
                                    $select[] = "IF(JSON_CONTAINS(`body`, {$jsonPh}, {$pathPh}), 1, 0) as `m{$i}`";
                                    $where[] = "(JSON_CONTAINS(`body`,  {$jsonPh}, {$pathPh})
                                            OR JSON_CONTAINS(`body`,  {$starPh}, {$pathPh})
                                            OR JSON_VALUE(`body`, {$pathPh}) = '*')";
                                    $fields[] = "m{$i}";
                                    $i++;
                                }
                            }
                        }
                    }
                } else {
                    $pathPh = ":p{$i}";
                    $valPh = ":v{$i}";
                    $params[$pathPh] = '$.' . $key;
                    $params[$valPh] = is_scalar($val) ? (string)$val : json_encode($val);
                    $select[] = "IF(JSON_VALUE(`body`, {$pathPh}) = {$valPh}, 1, 0) as `m{$i}`";
                    $where[] = "(JSON_VALUE(`body`, {$pathPh}) = {$valPh} OR JSON_VALUE(`body`, {$pathPh}) = '*')
            ";
                    $fields[] = "m{$i}";
                }
                $i++;
            }
        } else {
            $where = ['1'];
        }

        $selects = '';
        $order = '';
        if ($fields) {
            $select[] = "(SELECT " . implode("+", $fields) . ") as `bestMatch`";
            $selects = ", " . implode(', ', $select);
            $order = "ORDER BY bestMatch DESC";
        }

        $clause = ($where) ? implode(" AND ", $where) : '1';

        $sql = "SELECT `id_api_rbac` {$selects} FROM `api_rbac` WHERE
            `model` = :model AND
            `action` = :action AND
            `method` = :method AND
            " . $clause . "
            {$order}
            LIMIT 1
            ";

        $params[':model'] = $this->args['model'];
        $params[':action'] = $this->args['action'];
        $params[':method'] = \App\ApiRbacPeer::getSqlValueForEnum('api_rbac.method', $this->args['method']);

        $con = \Propel::getConnection(_DATA_SRC);
        $stmt = $con->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();
        $result = $stmt->fetch();

        if (is_array($result)) {
            return $result['id_api_rbac'];
        } else {
            return false;
        }
    }

    /**
     * Produce 1 or 2 search string
     *
     * @param array $body
     * @return void
     */
    public function getBodyWildcarded($body)
    {
        $return = [];
        if (is_array($body)) {
            foreach ($body as $key => $val) {
                if ($key == 'query') {
                    $return[0][$key] = $val;
                    $return[1][$key] = "*";
                    foreach ($val as $methods => $params) {
                        if ($methods == "filter") {
                            foreach ($params as $table => $filters) {
                                if (is_array($filters)) {
                                    foreach ($filters as $filter) {
                                        if ($filter[2]) {
                                            $return[2]['query']['filter'][$table][] = [$filter[0], '*', $filter[2]];
                                        } else {
                                            $return[2]['query']['filter'][$table][] = [$filter[0], '*'];
                                        }
                                    }
                                }
                            }
                        } else {
                            $return[2][$key][$methods] = $params;
                        }
                    }
                } else {
                    $return[0][$key] = "*";
                    $return[1][$key] = "*";
                    $return[2][$key] = "*";
                }
            }
        } else {
            return null;
        }
        $this->prettyBody = json_encode($body, \JSON_PRETTY_PRINT);

        $array0 = \json_encode($return[0]);
        $array1 = \json_encode($return[1]);
        $array2 = \json_encode($return[2]);
        if ($array0 == $array1) {
            return [$array0];
        } else {
            return [$array0, $array1, $array2];
        }
    }
}