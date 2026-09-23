<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Services;

use ApiGoat\Services\PushService;
use PHPUnit\Framework\TestCase;

/**
 * Wave 4 (2026-09-23): registering an Expo push token that is already bound
 * to ANOTHER user used to overwrite id_authy on the old row in place. Now
 * the old binding is deleted and a fresh row is inserted for the caller;
 * the outcome is logged server-side only (the HTTP response is identical).
 */
final class PushDeviceBindingTest extends TestCase
{
    /** @var array<string,object> token => device */
    private array $db = [];
    private string $log;
    private string $prevLog;

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'push');
        $this->prevLog = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLog);
        @unlink($this->log);
    }

    private function device(): object
    {
        $db = &$this->db;
        return new class ($db) {
            public ?string $token = null;
            public int $idAuthy = 0;
            public ?string $platform = null;
            public bool $deleted = false;
            public function __construct(private array &$db) {}
            public function setToken($t) { $this->token = $t; }
            public function setIdAuthy($id) { $this->idAuthy = (int) $id; }
            public function getIdAuthy() { return $this->idAuthy; }
            public function setPlatform($p) { $this->platform = $p; }
            public function save() { $this->db[$this->token] = $this; }
            public function delete() { $this->deleted = true; unset($this->db[$this->token]); }
        };
    }

    private function bind(int $user, string $token, string $platform = 'ios'): string
    {
        return PushService::bindDevice(
            $user,
            $token,
            $platform,
            fn (string $t) => $this->db[$t] ?? null,
            fn () => $this->device()
        );
    }

    public function testFirstRegistrationInserts(): void
    {
        $this->assertSame('inserted', $this->bind(7, 'ExponentPushToken[a]'));
        $this->assertSame(7, $this->db['ExponentPushToken[a]']->idAuthy);
    }

    public function testSameUserRefreshesInPlace(): void
    {
        $this->bind(7, 'ExponentPushToken[a]', 'ios');
        $row = $this->db['ExponentPushToken[a]'];
        $this->assertSame('refreshed', $this->bind(7, 'ExponentPushToken[a]', 'android'));
        $this->assertSame($row, $this->db['ExponentPushToken[a]']);
        $this->assertSame('android', $row->platform);
    }

    public function testOtherUsersTokenDropsOldBindingAndInsertsFresh(): void
    {
        $this->bind(7, 'ExponentPushToken[a]');
        $old = $this->db['ExponentPushToken[a]'];

        $this->assertSame('rebound', $this->bind(9, 'ExponentPushToken[a]'));

        $this->assertTrue($old->deleted, 'old owner binding deleted');
        $this->assertSame(7, $old->idAuthy, 'old row never mutated to the new owner');
        $new = $this->db['ExponentPushToken[a]'];
        $this->assertNotSame($old, $new);
        $this->assertSame(9, $new->idAuthy);
        $this->assertStringContainsString('rebound from user 7 to user 9', (string) file_get_contents($this->log));
    }

    public function testAnonymousCallerRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bind(0, 'ExponentPushToken[a]');
    }
}
