<?php

namespace ApiGoat\Tests\Mail;

use PHPMailer\PHPMailer\SMTP;

/** Scripted PHPMailer SMTP client: $answers[method] = bool (default true), $codes[method] = SMTP code on failure. Records every call. */
class FakeSmtp extends SMTP
{
    public array $calls = [];
    public array $answers = [];
    public array $codes = [];
    public array $options = [];
    /** method => \Throwable to throw instead of answering */
    public array $throws = [];
    /** method => [Timeout, Timelimit] at call time */
    public array $timeouts = [];
    private array $err = ['error' => '', 'detail' => '', 'smtp_code' => '', 'smtp_code_ex' => ''];

    private function step(string $name, array $args): bool
    {
        $this->calls[] = array_merge([$name], $args);
        $this->timeouts[$name] = [$this->Timeout, $this->Timelimit];
        if (isset($this->throws[$name])) {
            throw $this->throws[$name];
        }
        $ok = $this->answers[$name] ?? true;
        if (!$ok) {
            $this->err = ['error' => "{$name} failed", 'detail' => '', 'smtp_code' => (string) ($this->codes[$name] ?? ''), 'smtp_code_ex' => ''];
        }
        return $ok;
    }

    public function connect($host, $port = null, $timeout = 30, $options = []) { $this->options = $options; return $this->step('connect', [$host, $port]); }
    public function hello($host = '') { return $this->step('hello', []); }
    public function startTLS() { return $this->step('startTLS', []); }
    public function authenticate($username, $password, $authtype = null, $OAuth = null) { return $this->step('authenticate', [$username]); }
    public function mail($from) { return $this->step('mail', [$from]); }
    public function recipient($address, $dsn = '') { return $this->step('recipient:' . $address, []) && ($this->answers['recipient'] ?? true); }
    public function data($msg_data) { return $this->step('data', [strlen($msg_data)]); }
    public function reset() { $this->calls[] = ['reset']; return true; }
    public function quit($close_on_error = true) { $this->calls[] = ['quit']; return true; }
    public function close() { $this->calls[] = ['close']; }
    public function getError() { return $this->err; }
    public function getLastReply() { return (string) ($this->err['smtp_code'] ?? ''); }
}
