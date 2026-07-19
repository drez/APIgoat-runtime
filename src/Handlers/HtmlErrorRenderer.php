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

    protected $request;
    protected $displayErrorDetails;

    // No constructor: $request / $displayErrorDetails default to null. The
    // previous body assigned from two undefined local variables (a PHP warning
    // that left $this->request null anyway).

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

        if (!empty($this->request['a'])) {
            if ($displayErrorDetails) {
                $errorMessage = $detailedErrorMessage;
            }

            return scriptReady("alertb('Error', '{$errorMessage}');");
        }

        if ($displayErrorDetails) {
            return $detailedErrorMessage;
        }



        // Detect error type


        return $errorMessage;
    }
}
