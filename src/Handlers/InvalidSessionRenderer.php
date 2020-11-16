<?php

namespace ApiGoat\Handlers;

use Psr\Http\Message\ServerRequestInterface;

class InvalidSessionRenderer
{

    private $redirect = false;
    private $message = '';

    public function __construct($isApi, string $message)
    {
        if ($isApi) {
            $this->message = ['status' => 'failure', 'errors' => [$message]];
        } else {
            if ($this->redirect) {
                $jsRedirect = script("setTimeout(function (){ window.location.href = '" . _SITE_URL . "' }, 1000);");
            }

            $this->message = div(div(_($message), '', "class='expired-session-msg'"), '', "class='expired-session-msg-ctnr'")
                . $jsRedirect;
        }
        return $this;
    }

    public function setRedirect()
    {
        $this->redirect = true;
    }

    public function getMessage()
    {
        return $this->message;
    }
}
