<?php
namespace ApiGoat\Utility;
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
            $message = $exception->getMessage();
        }
        $code = $exception->getCode();
        $file = $exception->getFile();
        $line = $exception->getLine();
        $trace = $exception->getTraceAsString();
        $error = sprintf('[%s] %s in %s on line %s.', $code, $message, $file, $line);
        $error .= sprintf("<br>Backtrace:<br>%s", str_replace("#", "<br>#", $trace));
        if ($maxLength > 0) {
            $error = substr($error, 0, $maxLength);
        }
        return $error;
    }
}