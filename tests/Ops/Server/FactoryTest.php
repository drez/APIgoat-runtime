<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops\Server;

use ApiGoat\Ops\Server\Factory;
use ApiGoat\Ops\Server\SnapshotFileSource;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/Ops/Server/Source.php';
require_once __DIR__ . '/../../../src/Ops/Server/SnapshotFileSource.php';
require_once __DIR__ . '/../../../src/Ops/Server/Factory.php';

/**
 * Factory::make() is the only place server_source strings get turned into a
 * concrete Source. R4 (controller ruling, Task 6): 'snapshot' is the only
 * source with an implementation — IspconfigSource was ruled out entirely
 * (prod is ISPConfig-jailed, no MySQL/root for the app user to read
 * dbispconfig.monitor_data), so 'ispconfig' maps to null exactly like
 * 'none' and any unrecognized string (e.g. a config typo).
 */
final class FactoryTest extends TestCase
{
    public function test_snapshot_source_returns_a_snapshot_file_source(): void
    {
        $source = Factory::make('snapshot', '/tmp/whatever.json');
        $this->assertInstanceOf(SnapshotFileSource::class, $source);
    }

    public function test_none_source_returns_null(): void
    {
        $this->assertNull(Factory::make('none', '/tmp/whatever.json'));
    }

    public function test_ispconfig_source_returns_null(): void
    {
        // R4: IspconfigSource was never built — 'ispconfig' is treated the
        // same as 'none' or any other unrecognized value.
        $this->assertNull(Factory::make('ispconfig', '/tmp/whatever.json'));
    }

    public function test_unrecognized_source_returns_null(): void
    {
        $this->assertNull(Factory::make('carrier-pigeon', '/tmp/whatever.json'));
    }

    public function test_empty_path_falls_back_to_the_default_path(): void
    {
        $source = Factory::make('snapshot', '');
        $this->assertInstanceOf(SnapshotFileSource::class, $source);
    }

    public function test_null_path_falls_back_to_the_default_path(): void
    {
        $source = Factory::make('snapshot', null);
        $this->assertInstanceOf(SnapshotFileSource::class, $source);
    }

    public function test_default_path_is_the_project_relative_tmp_snapshot_when_base_dir_is_defined(): void
    {
        // _BASE_DIR is defined once, globally, by config/Built/config.php in
        // a real project; here we assert Factory's fallback shape rather
        // than defining the constant ourselves (it may already be defined
        // by a sibling test process / bootstrap).
        $source = Factory::make('snapshot');
        $this->assertInstanceOf(SnapshotFileSource::class, $source);

        $ref = new \ReflectionProperty(SnapshotFileSource::class, 'path');
        $ref->setAccessible(true);
        $path = $ref->getValue($source);

        if (\defined('_BASE_DIR')) {
            $this->assertSame(\_BASE_DIR . 'tmp/ops-snapshot.json', $path);
        } else {
            $this->assertSame('tmp/ops-snapshot.json', $path);
        }
    }
}
