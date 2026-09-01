<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\TokenSource;

final class FakeTokenSource implements TokenSource
{
    public int $issued = 0;
    public int $invalidated = 0;

    public function accessToken(): string
    {
        $this->issued++;
        return 'tok-' . $this->issued;
    }

    public function invalidate(): void
    {
        $this->invalidated++;
    }

    public function describe(): string
    {
        return 'fake:u@x';
    }
}
