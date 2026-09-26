<?php

namespace ApiGoat\Ops\Dashboard;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Route handlers for the shared dashboards — Security/dashboard,
 * Performance/dashboard and the Chart.js asset they load (Ops/chart.js).
 * The routes are emitted by with_ops_monitor into config/Built/routes.php
 * (Classes/Routes.php), so every app with the behavior gets them on build.
 * The pages are login-gated like every page; the views themselves refuse
 * non-admins.
 */
final class Page
{
    public static function security(Request $request, Response $response, array $args): Response
    {
        return self::render($response, $args, Extras::topNav('security') . (new SecurityView())->render($request->getQueryParams()));
    }

    public static function performance(Request $request, Response $response, array $args): Response
    {
        return self::render($response, $args, Extras::topNav('performance') . (new PerformanceView())->render($request->getQueryParams()));
    }

    /** The vendored Chart.js (4.4.4 UMD), long-cached: its URL changes only with the runtime. */
    public static function chartJs(Request $request, Response $response): Response
    {
        $response->getBody()->write((string) \file_get_contents(\dirname(__DIR__, 3) . '/assets/chart.umd.min.js'));

        return $response
            ->withHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'private, max-age=86400');
    }

    private static function render(Response $response, array $args, string $html): Response
    {
        $layout = new \ApiGoat\Utility\BuilderLayout(new \ApiGoat\Utility\BuilderMenus($args));
        $response->getBody()->write($layout->render(['html' => swheader() . $html]));

        return $response;
    }
}
