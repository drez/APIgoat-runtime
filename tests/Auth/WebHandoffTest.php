<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\WebHandoff;
use PHPUnit\Framework\TestCase;

/**
 * Native app -> web session handoff: a single-use, 60 s code bound to one
 * user and one internal path (the app opens a web dashboard in a WebView).
 */
final class WebHandoffTest extends TestCase
{
    protected function setUp(): void
    {
        WebHandoff::useStore([]);   // in-memory store (test seam; prod = APCu)
    }

    protected function tearDown(): void
    {
        WebHandoff::useStore(null);
    }

    public function test_a_code_works_once_for_its_user_and_path(): void
    {
        $code = WebHandoff::mint(7, 'Dashboard/finance', 1000);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $code);
        $this->assertSame(['id_authy' => 7, 'path' => 'Dashboard/finance'], WebHandoff::consume($code, 1010));
        $this->assertNull(WebHandoff::consume($code, 1011), 'single use');
    }

    public function test_a_code_expires_after_60_seconds(): void
    {
        $code = WebHandoff::mint(7, 'Performance/dashboard', 1000);
        $this->assertNull(WebHandoff::consume($code, 1061));
    }

    public function test_garbage_and_unknown_codes_are_refused(): void
    {
        $this->assertNull(WebHandoff::consume('nope', 1000));
        $this->assertNull(WebHandoff::consume(str_repeat('a', 64), 1000));
    }

    /** @dataProvider paths */
    public function test_only_plain_internal_paths_are_accepted(string $path, bool $ok): void
    {
        $this->assertSame($ok, WebHandoff::validPath($path), $path);
    }

    public static function paths(): array
    {
        return [
            ['Dashboard/finance', true],
            ['Performance/dashboard', true],
            ['Analytics/dashboard?site=2', true],
            ['Client/edit/12', true],
            ['//evil.com', false],
            ['https://evil.com/x', false],
            ['/Dashboard/finance', false],
            ['Dashboard/../x', false],
            ['Authy/logout', false],
            ['WebHandoff/open', false],
            ['oauth/authorize', false],
            ['', false],
            ["Dashboard/finance\nX", false],
        ];
    }

    public function test_mint_refuses_a_bad_path_or_user(): void
    {
        $this->assertNull(WebHandoff::mint(7, '//evil.com', 1000));
        $this->assertNull(WebHandoff::mint(0, 'Dashboard/finance', 1000));
    }

    public function test_without_a_shared_store_minting_is_refused(): void
    {
        WebHandoff::useStore(null);
        WebHandoff::forceShared(false);
        try {
            $this->assertNull(WebHandoff::mint(7, 'Dashboard/finance', 1000), 'a per-process store would lose the code between requests');
        } finally {
            WebHandoff::forceShared(null);
        }
    }
}
