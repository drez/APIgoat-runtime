<?php

use ApiGoat\Handlers\BuilderReturn;
use PHPUnit\Framework\TestCase;

// The runtime package declares no autoload section of its own; the test suite
// runs on a project's vendor/autoload.php, which maps ApiGoat\ to the INSTALLED
// clone, not this working tree.
require_once __DIR__ . '/../../src/Handlers/BuilderReturn.php';

if (!function_exists('_')) {
    function _($s) { return $s; }
}

/**
 * return_error() takes two shapes of $error from the emitted Service:
 *
 *  - PropelErrorHandler::getValidationErrors(): the ready-made envelope
 *    ['error' => 'yes', 'onReadyJs' => <field marking + alertb()>, 'txt' => ...]
 *    — the JS must run as-is; flattening it turned the flag and the whole
 *    script into the alert text ("yes document.querySelectorAll(...) ...").
 *  - a bare list of message strings — surfaced through a generic alertb().
 */
final class BuilderReturnErrorTest extends TestCase
{
    private function request(): array
    {
        return ['ui' => 'protoEditScreen', 'ret' => '', 'return' => '', 'diag' => '', 'a' => 'update', 'p' => 'Learner', 'action' => 'edit'];
    }

    public function testPropelErrorHandlerEnvelopeIsPassedThroughUntouched(): void
    {
        $js = "document.querySelectorAll('#protoEditScreen .error_field').forEach(function (__e) { __e.classList.remove('error_field'); });"
            . "alertb('Form field', 'This username is already in use. <br>');alert_close = function(){};";
        $error = ['error' => 'yes', 'onReadyJs' => $js, 'txt' => 'This username is already in use.<br>'];

        $ret = (new BuilderReturn($this->request(), $error, null))->return();

        $this->assertSame('yes', $ret['error']);
        $this->assertSame($js, $ret['onReadyJs'], 'the handler script must reach the client verbatim');
        $this->assertSame(['This username is already in use.'], $ret['messages']);
        foreach (['html', 'js', 'json'] as $k) {
            $this->assertArrayHasKey($k, $ret);
        }
        $this->assertStringNotContainsString("'yes", $ret['onReadyJs']);
    }

    public function testBareMessageListIsSurfacedThroughAlertb(): void
    {
        $ret = (new BuilderReturn($this->request(), ['Something broke', ['nested' => 'Also this']], null))->return();

        $this->assertSame('yes', $ret['error']);
        $this->assertSame(['Something broke', 'Also this'], $ret['messages']);
        $this->assertStringContainsString("alertb('Alert', 'Something broke Also this');", $ret['onReadyJs']);
        $this->assertStringContainsString('#formLearner #saveLearner', $ret['onReadyJs']);
    }
}
