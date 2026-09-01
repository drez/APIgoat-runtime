<?php

namespace ApiGoat\Mail\Token;

use ApiGoat\Google\ClientFactory;
use ApiGoat\Mail\TokenSource;

/**
 * Domain-Wide Delegation: a Workspace service account impersonates the
 * mailbox owner. Token minting/caching lives in {@see ClientFactory}.
 */
final class DwdTokenSource implements TokenSource
{
    /** @param string[] $scopes */
    public function __construct(
        private ClientFactory $google,
        private string $subject,
        private array $scopes = [ClientFactory::SCOPE_GMAIL_READONLY],
    ) {
        if (trim($this->subject) === '') {
            throw new \InvalidArgumentException('DwdTokenSource needs the impersonated user (subject)');
        }
    }

    public function accessToken(): string
    {
        return $this->google->getAccessToken($this->scopes, $this->subject);
    }

    public function invalidate(): void
    {
        $this->google->forgetToken($this->scopes, $this->subject);
    }

    public function describe(): string
    {
        return 'dwd:' . $this->subject;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    /** @return string[] */
    public function scopes(): array
    {
        return $this->scopes;
    }
}
