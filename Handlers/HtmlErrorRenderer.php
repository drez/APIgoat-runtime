<?php

namespace ApiGoat\Handlers;

use ApiGoat\Utility\ExceptionDetail;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorRendererInterface;

use Throwable;

/**
 * Html Error Renderer
 */
class HtmlErrorRenderer implements ErrorRendererInterface
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
        $this->request = $request;
        $this->displayErrorDetails = $displayErrorDetails;
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
        $detailedErrorMessage = ExceptionDetail::getExceptionHtml($exception);

        if ($exception instanceof HttpNotFoundException) {
            $errorMessage = '404 Not Found';
            return $errorMessage;
        } elseif ($exception instanceof HttpMethodNotAllowedException) {
            $errorMessage = '405 Method Not Allowed';
            return $errorMessage;
        } else {
            $errorMessage = '500 Internal Server Error';
        }

        if ($this->request['a']) {
            if ($displayErrorDetails) {
                $errorMessage = $displayErrorDetails;
            }

            return scriptReady("$('#alertDialog').html('{$errorMessage}').dialog('open');");
        }

        if ($displayErrorDetails) {
            return $detailedErrorMessage;
        }



        // Detect error type


        return $errorMessage;
    }
}
