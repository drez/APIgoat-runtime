<?php

namespace ApiGoat\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Description of RecallMiddleware
 *
 * @author sysadmin
 */
class RecallMiddleware implements MiddlewareInterface
{
    public function __construct(array $standard_actions)
    {
        $this->standard_actions = $standard_actions;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = str_replace(_SUB_DIR_URL, '', $request->getUri()->getPath());
        $url_parts = explode('/', $path);
        $entity = $url_parts[0];

        if (!in_array($url_parts[1], $this->standard_actions)) {
            $child = $url_parts[1];
        }

        switch ($request->getMethod()) {
            case 'GET':
                $args = $request->getQueryParams();
                break;
            case 'POST':
                $args = $request->getParsedBody();
                break;
        }

        if ($_SESSION[_AUTH_VAR]->get('connected') == 'YES') {

            $mem = [
                'list' => [$args['ip'] => $child],
            ];

            if ($args['ms'] == 'clear') {
                $args['ms'] = '';
                $args['order'] = '';
                $args['pg'] = '';
                unset($_SESSION['mem']['search']["{$entity}/{$child}"]);
                unset($_SESSION['mem']['order']["{$entity}/{$child}"]);
                unset($_SESSION['mem']['page']["{$entity}/{$child}"]);
                //unset($_SESSION['mem']);
            }

            if (!empty($args['pg'])) {
                $_SESSION['mem']['page']["{$entity}/{$child}"] = $args['pg'];
            }

            if (!empty($args['order'])) {
                $order = json_decode($args['order'], true);
                $_SESSION['mem']['order']["{$entity}/{$child}"][$order['col']] = $order['sens'];
            }

            if (!empty($args['ms'])) {
                $_SESSION['mem']['search']["{$entity}/{$child}"] = urldecode($args['ms']);
            }

            //echo "<br>Saved: ";preprint($_SESSION['mem']);echo "<br>";

        }
        $response = $handler->handle($request);
        return $response;
    }
    //put your code here
}
