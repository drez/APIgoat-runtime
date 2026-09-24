<?php
namespace ApiGoat\Utility;
use Slim\Exception\HttpNotFoundException;
use Throwable;
/**
 * Class ExceptionDetail.
 */
final class ExceptionDetail
{
    /**
     * Get exception text.
     *
     * @param Throwable $exception Error
     * @param int $maxLength The max length of the error message
     *
     * @return string The full error message
     */
    public static function getExceptionText(Throwable $exception, int $maxLength = 0): string
    {
        $code = $exception->getCode();
        $file = $exception->getFile();
        $line = $exception->getLine();
        $message = $exception->getMessage();
        $trace = $exception->getTraceAsString();
        $error = sprintf('[%s] %s in %s on line %s.', $code, $message, $file, $line);
        $error .= sprintf("\nBacktrace:\n%s", $trace);
        if ($maxLength > 0) {
            $error = substr($error, 0, $maxLength);
        }
        return $error;
    }
    
    public static function getExceptionHtml(Throwable $exception, int $maxLength = 0): string
    {
        
        if ($exception instanceof HttpNotFoundException) {
            $message = '404 Not Found<br>';
        }else{
            // SECURITY: message/trace can carry request-controlled text; escape for HTML.
            $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        $code = htmlspecialchars((string) $exception->getCode(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $exception->getLine();
        $trace = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8');
        $error = sprintf('[%s] %s in %s on line %s.', $code, $message, $file, $line);
        $error .= sprintf("<br>Backtrace:<br>%s", str_replace("#", "<br>#", $trace));
        if ($maxLength > 0) {
            $error = substr($error, 0, $maxLength);
        }
        return $error;
    }
}