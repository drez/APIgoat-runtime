<?php

namespace ApiGoat\Mail\Smtp;

/** Where and how to submit mail. The password never appears in a dump. */
final class SmtpSettings
{
    public const STARTTLS = 'StartTls';
    public const TLS      = 'Tls';
    public const NONE     = 'None';

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $security,
        public readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        public readonly int $timeout = 30,
    ) {
        if (trim($host) === '' || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('SmtpSettings: a host and a port 1-65535 are required');
        }
        if (!in_array($security, [self::STARTTLS, self::TLS, self::NONE], true)) {
            throw new \InvalidArgumentException("SmtpSettings: unknown security '{$security}'");
        }
    }

    public function password(): string
    {
        return $this->password;
    }

    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'security' => $this->security, 'username' => $this->username, 'timeout' => $this->timeout];
    }
}
