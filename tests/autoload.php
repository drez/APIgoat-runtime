<?php
/**
 * Autoloader resolution for the standalone (php tests/X.php) tests.
 *
 * These tests declare classes that implement PSR contracts, so the contracts
 * must be on the autoloader before the file under test is required. Where
 * those contracts live depends on how this checkout is being used:
 *
 *   - runtime as its own repo   → vendor/ right here
 *   - runtime inside a project  → <project>/.admin/vendor/ three levels up
 *
 * The tests used to hardcode the second form. In the standalone repo that
 * path resolves to the ORCHESTRATOR's vendor/, which carries none of the
 * runtime's dependencies, so the test died on a missing PSR interface before
 * it ran a single assertion. Try both, nearest first.
 *
 * @return string the autoloader that was loaded
 */
return (static function (): string {
    foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $candidate) {
        if (is_file($candidate)) {
            require_once $candidate;
            if (interface_exists(\Psr\Http\Server\MiddlewareInterface::class)) {
                return $candidate;
            }
        }
    }
    fwrite(STDERR, "tests/autoload.php: no autoloader provides the PSR HTTP contracts\n");
    exit(1);
})();
