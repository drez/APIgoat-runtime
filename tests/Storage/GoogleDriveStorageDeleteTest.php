<?php

use ApiGoat\Storage\Drive\Exceptions\TransientError;
use ApiGoat\Storage\Drive\GoogleClientFactory;
use ApiGoat\Storage\Drive\GoogleDriveStorage;
use PHPUnit\Framework\TestCase;

/**
 * delete() must TRASH (PATCH trashed:true), never files.delete — a UI delete
 * has to stay recoverable (~30 days). 404 = already gone = false, per the
 * FileStorageInterface contract.
 */
final class GoogleDriveStorageDeleteTest extends TestCase
{
    /** @return GoogleClientFactory a stub recording patch() calls; delete() forbidden */
    private static function factory(array &$calls, ?\Throwable $patchThrows = null): GoogleClientFactory
    {
        return new class($calls, $patchThrows) extends GoogleClientFactory {
            private array $callsRef;
            private ?\Throwable $throws;
            public function __construct(array &$calls, ?\Throwable $throws)
            {
                // no parent::__construct — the signer is never used here
                $this->callsRef = &$calls;
                $this->throws   = $throws;
            }
            public function patch(string $url, array $payload, array $scopes, ?string $subject = null): array
            {
                if ($this->throws) {
                    throw $this->throws;
                }
                $this->callsRef[] = ['method' => 'PATCH', 'url' => $url, 'payload' => $payload, 'subject' => $subject];
                return ['id' => 'F1', 'trashed' => true];
            }
            public function delete(string $url, array $scopes, ?string $subject = null): bool
            {
                throw new \LogicException('files.delete must not be used by delete() — it bypasses the trash');
            }
        };
    }

    public function testDeleteTrashesInsteadOfHardDeleting(): void
    {
        $calls   = [];
        $storage = new GoogleDriveStorage(self::factory($calls), 'user@example.com');

        $this->assertTrue($storage->delete('F1'));
        $this->assertCount(1, $calls);
        $this->assertSame('PATCH', $calls[0]['method']);
        $this->assertSame(['trashed' => true], $calls[0]['payload']);
        $this->assertStringContainsString('/files/F1', $calls[0]['url']);
        $this->assertSame('user@example.com', $calls[0]['subject']);
    }

    public function testAlreadyGoneReturnsFalse(): void
    {
        $calls   = [];
        $storage = new GoogleDriveStorage(
            self::factory($calls, new TransientError('not found', 404)),
            'user@example.com'
        );
        $this->assertFalse($storage->delete('GONE'));
    }

    public function testOtherHttpErrorsPropagate(): void
    {
        $calls   = [];
        $storage = new GoogleDriveStorage(
            self::factory($calls, new TransientError('rate limited', 429)),
            'user@example.com'
        );
        $this->expectException(TransientError::class);
        $storage->delete('F1');
    }
}
