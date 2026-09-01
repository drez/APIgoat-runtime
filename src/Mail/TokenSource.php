<?php

namespace ApiGoat\Mail;

/**
 * Where a Gmail connector gets its bearer token. The connector is one class;
 * Domain-Wide Delegation vs per-user OAuth differ ONLY here.
 *
 * Implementations throw ApiGoat\Sync\Exceptions\AuthFailed when the token
 * cannot be minted/refreshed (revoked consent, bad key, missing DWD grant).
 */
interface TokenSource
{
    /** A currently valid access token (cached until near expiry). */
    public function accessToken(): string;

    /** Drop the cached token — called after a 401 so the next accessToken() re-mints once. */
    public function invalidate(): void;

    /** Short label for logs/errors: "dwd:user@domain" / "oauth:user@domain". Never a secret. */
    public function describe(): string;
}
