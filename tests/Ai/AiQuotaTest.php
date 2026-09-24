<?php

declare(strict_types=1);

// In-memory stand-ins for the emitted authy_log Propel classes AiQuota uses.
namespace {
    if (!class_exists('Criteria')) {
        class Criteria { const GREATER_EQUAL = '>='; const NOT_EQUAL = '<>'; }
    }
}

namespace App {
    if (!class_exists(AuthyLog::class, false)) {
        class AuthyLog
        {
            /** @var AuthyLog[] */
            public static array $rows = [];
            public array $d = [];
            public function setEvent($v) { $this->d['event'] = $v; }
            public function setIp($v) { $this->d['ip'] = $v; }
            public function setLogin($v) { $this->d['login'] = $v; }
            public function setResult($v) { $this->d['result'] = $v; }
            public function setTimestamp($v) { $this->d['timestamp'] = $v; }
            public function save() { if (!in_array($this, self::$rows, true)) { self::$rows[] = $this; } }
        }

        class AuthyLogQuery
        {
            private array $f = [];
            public static function create(): self { return new self(); }
            public function filterByEvent($v) { $this->f[] = fn ($d) => $d['event'] === $v; return $this; }
            public function filterByIp($v) { $this->f[] = fn ($d) => $d['ip'] === $v; return $this; }
            public function filterByTimestamp($v, $op) { $this->f[] = fn ($d) => $d['timestamp'] >= $v; return $this; }
            public function filterByResult($v, $op) { $this->f[] = fn ($d) => $d['result'] !== $v; return $this; }
            public function count(): int
            {
                return count(array_filter(AuthyLog::$rows, function ($r) {
                    foreach ($this->f as $f) {
                        if (!$f($r->d)) {
                            return false;
                        }
                    }
                    return true;
                }));
            }
        }
    }
}

namespace ApiGoat\Tests\Ai {
    use ApiGoat\Ai\AiManifest;
    use ApiGoat\Ai\AiQuota;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../../src/Ai/AiManifest.php';
    require_once __DIR__ . '/../../src/Ai/AiQuota.php';

    final class AiQuotaTest extends TestCase
    {
        protected function setUp(): void
        {
            \App\AuthyLog::$rows = [];
            $p = new \ReflectionProperty(AiManifest::class, 'cache');
            $p->setValue(null, ['quota' => ['max' => 2, 'window' => 60]]);
        }

        protected function tearDown(): void
        {
            AiManifest::reset();
        }

        public function testAtMostMaxPassPerWindow(): void
        {
            self::assertTrue(AiQuota::allow('1.2.3.4'));
            self::assertTrue(AiQuota::allow('1.2.3.4'));
            self::assertFalse(AiQuota::allow('1.2.3.4'));
            self::assertTrue(AiQuota::allow('5.6.7.8'), 'other subjects have their own bucket');
        }

        public function testRefusedAttemptsDoNotExtendTheLockout(): void
        {
            AiQuota::allow('1.2.3.4');
            AiQuota::allow('1.2.3.4');
            for ($i = 0; $i < 5; $i++) {
                self::assertFalse(AiQuota::allow('1.2.3.4'));
            }
            // Only the 2 allowed calls count; the refused ones are marked throttled.
            $counted = array_filter(\App\AuthyLog::$rows, fn ($r) => $r->d['result'] !== 'throttled');
            self::assertCount(2, $counted);
            // Once the allowed calls age out of the window, the subject is let back in.
            foreach (\App\AuthyLog::$rows as $r) {
                $r->d['timestamp'] -= 120;
            }
            self::assertTrue(AiQuota::allow('1.2.3.4'));
        }
    }
}
