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

    public function __construct(ResponseFactoryInterface $responseFactory = null)
    {
        $Configuration = new Configuration(require _BASE_DIR . 'config/settings.php');
        $this->config = $Configuration->getArray('rbac');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $error = [];
        $this->args = $request->getAttribute('parsed_args');
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
        if ($_SESSION[_AUTH_VAR]->get('connected') == 'YES') {
            if ((!empty($this->rbac_role) && $this->rbac_role != $_SESSION[_AUTH_VAR]->SessVar['IdRole']) || $this->rbac_rule == 'Deny') {
                return ['Route denied. Check your API access control.'];
            }
        } else {
            return ['Route denied, private route require authentication.'];
        }

        $ApiRbac = ApiRbacQuery::create()->findPk($rbac_id);
        if ($ApiRbac) {
            $ApiRbac->setCount($ApiRbac->getCount() + 1);
            $ApiRbac->save();
            $this->logApi($rbac_id, $_SESSION[_AUTH_VAR]->getIdAuthy());
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
            $bestMatch = $this->findBestMatch();

            if ($bestMatch) {
                $ApiRbac = \App\ApiRbacQuery::create()->findPk($bestMatch);
            }
            //get the wildcard rule
            $wildBody = $this->getBodyWildcarded($this->args['data']);
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
            $ApiRbac->save();
            $this->rbac_rule = $default_rule;
            $this->rbac_role = null;
            $this->rbac_is_new = true;
            $this->rbac_id = $ApiRbac->getPrimaryKey();
            $this->logApi($this->rbac_id);
            if(\defined('app_status') && \app_status == 'dev'){
                return false;
            }
            return true;
        } elseif ($ApiRbac->getScope() == 'Public' && $ApiRbac->getRule() != 'Deny') {
            // pass public route
            $ApiRbac->setCount($ApiRbac->getCount() + 1);
            $ApiRbac->save();
            $this->rbac_id = $ApiRbac->getPrimaryKey();
            $this->logApi($this->rbac_id);
            return false;
        } else {
            // failed
            $this->rbac_rule = $ApiRbac->getRule();
            $this->rbac_role = null;
            $this->rbac_id = $ApiRbac->getPrimaryKey();
            if(\defined('app_status') && \app_status == 'dev'){
                return false;
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
        $ApiLog->save();
    }

    function excludeBody()
    {
        if (is_array($this->config['excludes'])) {
            foreach ($this->config['excludes'] as $body => $exclude) {
                if (($exclude['method'] == '*' || $exclude['method'] == $this->args['method'])
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
        if (is_array($this->args['data'])) {
            foreach ($this->args['data'] as $key => $val) {
                if ($key == 'query') {
                    if ($this->args['data']['query']['select']) {
                        $path = 'query.select';
                        $select[] = "IF(JSON_CONTAINS(`body`, '" . json_encode($this->args['data']['query']['select']) . "', '$.{$path}'), 1, 0) as 'm{$i}'";
                        $where[] = "(JSON_CONTAINS(`body`,  '" . json_encode($this->args['data']['query']['select']) . "', '$.{$path}')
                             OR JSON_CONTAINS(`body`,  '[[\"" . $val[0] . "\", \"*\"]]', '$.{$path}')
                             OR JSON_VALUE(`body`, '$.{$path}') = '*')
            ";
                        $fields[] = "m{$i}";
                        $i++;
                    }


                    if (is_array($this->args['data']['query']['filter'])) {
                        foreach ($this->args['data']['query']['filter'] as $model => $filters) {

                            if (is_array($filters)) {
                                foreach ($filters as $val) {
                                    $path = "query.filter." . $model;
                                    $select[] = "IF(JSON_CONTAINS(`body`, '[" . json_encode($val) . "]', '$.{$path}'), 1, 0) as 'm{$i}'";
                                    $where[] = "(JSON_CONTAINS(`body`,  '[" . json_encode($val) . "]', '$.{$path}')
                                            OR JSON_CONTAINS(`body`,  '[[\"" . $val[0] . "\", \"*\"]]', '$.{$path}')
                                            OR JSON_VALUE(`body`, '$.{$path}') = '*')";
                                    $fields[] = "m{$i}";
                                    $i++;
                                }
                            }
                        }
                    }
                } else {
                    $select[] = "JSON_VALUE(`body`, '$.{$key}')";
                    $select[] = "IF(JSON_VALUE(`body`, '$.{$key}') = '{$val}', 1, 0) as 'm{$i}'";
                    $where[] = "(JSON_VALUE(`body`, '$.{$key}') = '{$val}' OR JSON_VALUE(`body`, '$.{$key}') = '*')
            ";
                    $fields[] = "m{$i}";
                }
                $i++;
            }
        } else {
            $where = ['1'];
        }

        if (is_array($fields)) {
            $select[] = "(SELECT " . implode("+", $fields) . ") as 'bestMatch'";
            $selects = ", " . implode(', ', $select);
            $order = "ORDER BY bestMatch DESC";
        }

        $clause = ($where)?implode(" AND ", $where):'1';

        $sql = "SELECT `id_api_rbac` {$selects} FROM `api_rbac` WHERE 
            `model` = '" . $this->args['model'] . "' AND
            `action` = '" . $this->args['action'] . "' AND
            `method` = '" . \App\ApiRbacPeer::getSqlValueForEnum('api_rbac.method', $this->args['method']) . "' AND
            " . $clause . "
            {$order}
            LIMIT 1
            ";

        $con = \Propel::getConnection(_DATA_SRC);
        $stmt = $con->prepare($sql);
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
