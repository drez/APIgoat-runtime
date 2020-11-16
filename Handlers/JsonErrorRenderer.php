<?php

namespace ApiGoat\Handlers;

use ApiGoat\Utility\ExceptionDetail;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * JSON Error Renderer
 */
class JsonErrorRenderer implements ErrorRendererInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * The constructor.
     *
     * @param LoggerFactory $loggerFactory The logger factory
     */
    public function __construct()
    {
        /*$this->logger = $loggerFactory
            ->addFileHandler('json_error.log')
            ->createInstance('json_error_renderer');*/
    }

    /**
     * Invoke.
     *
     * @param Throwable $exception The exception
     * @param bool $displayErrorDetails Show error details
     *
     * @return string The result
     */
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        $detailedErrorMessage = ExceptionDetail::getExceptionText($exception);

        // Add error log entry
        //$this->logger->error($detailedErrorMessage);

        // Detect error type
        if ($exception instanceof HttpNotFoundException) {
            $errorMessage = '404 Not Found';
        } elseif ($exception instanceof HttpMethodNotAllowedException) {
            $errorMessage = '405 Method Not Allowed';
        } else {
            $errorMessage = '500 Internal Server Error';
        }

        $result = [
            'status' => 'failure',
            'data' => null,
            'error' => $errorMessage
        ];

        if ($displayErrorDetails) {
            $result['messages'] = $detailedErrorMessage;
        }

        return (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
