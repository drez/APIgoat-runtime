<?php

use ApiGoat\Handlers\BuilderReturn;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Handlers/BuilderReturn.php';

if (!function_exists('_')) {
    function _($s) { return $s; }
}
if (!defined('_SITE_URL')) {
    define('_SITE_URL', '/');
}

/** Request values (i, p, ui, data.pc/tp/ip) reach onReadyJs escaped, never as JS. */
final class BuilderReturnJsEscapeTest extends TestCase
{
    public function testDeleteReturnEscapesRequestValues(): void
    {
        $req = ['ui' => '', 'ret' => '', 'return' => '', 'diag' => '', 'a' => 'delete', 'action' => '',
            'p' => 'Invoice', 'i' => "1'];alert(1);//</script>"];
        $js = (new BuilderReturn($req, [], null))->return()['onReadyJs'];
        $this->assertStringNotContainsString("1'];alert", $js);
        $this->assertStringNotContainsString('</script>', $js);
        $this->assertStringContainsString('1\\u0027];alert(1);//\\u003C/script\\u003E', $js);
        // ordinary ids/model names are emitted exactly as before
        $this->assertStringContainsString("document.querySelector('#InvoiceTable tr[rid=\"1\\u0027", $js);
    }

    public function testRefreshChildEscapesDataValues(): void
    {
        $req = ['ui' => "x'}", 'ret' => '', 'return' => '', 'diag' => '', 'a' => 'update', 'action' => 'edit',
            'p' => 'Invoice', 'i' => '5', 'pc' => 'Client', 'jet' => 'refreshChild',
            'data' => ['pc' => "Client');fetch('//evil", 'tp' => 'Invoice', 'ip' => '3', 'no_close' => '']];
        $js = (new BuilderReturn($req, [], null))->return()['onReadyJs'];
        $this->assertStringNotContainsString("Client');fetch(", $js);
        $this->assertStringNotContainsString("pui:'x'}'", $js);
        $this->assertStringContainsString("fetch('/Client\\u0027);fetch(\\u0027//evil/Invoice/3?'", $js);

        $req['data']['pc'] = 'Client';
        $req['ui'] = 'ClientEdit';
        $js = (new BuilderReturn($req, [], null))->return()['onReadyJs'];
        $this->assertStringContainsString("fetch('/Client/Invoice/3?'", $js);
        $this->assertStringContainsString("pui:'ClientEdit'", $js);
    }
}
