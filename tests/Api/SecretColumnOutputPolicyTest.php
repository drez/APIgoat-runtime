<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Api;

use ApiGoat\Api\Api;
use PHPUnit\Framework\TestCase;

/**
 * Review 3 #12 (2026-09-23): the generic API / MCP output only stripped the
 * credential columns, so per-model secrets (Campaign.EventSecret,
 * Quote/Invoice.PublicToken, PushDevice.Token) were returned to any reader
 * and were filterable (a LIKE + count oracle). The generated service now
 * passes its SecretColumns list as the 4th `new Api(...)` argument.
 */
final class SecretColumnOutputPolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../src/Utility/Legacy/html_helper.php'; // \camelize()
    }

    private function call(Api $api, string $method, ...$args)
    {
        $m = new \ReflectionMethod($api, $method);
        $m->setAccessible(true);
        return $m->invoke($api, ...$args);
    }

    public function testSecretColumnsAreStrippedFromRows(): void
    {
        $api = new Api('invoice', null, null, ['PublicToken']);
        $rows = [
            ['IdInvoice' => 1, 'Number' => 'I-1', 'PublicToken' => 'abc', 'public_token' => 'abc'],
            ['IdInvoice' => 2, 'Number' => 'I-2', 'PublicToken' => 'def'],
        ];
        $out = $this->call($api, 'stripSensitiveOutput', $rows);
        $this->assertSame([['IdInvoice' => 1, 'Number' => 'I-1'], ['IdInvoice' => 2, 'Number' => 'I-2']], $out);

        $one = $this->call($api, 'stripSensitiveOutput', ['IdInvoice' => 1, 'PublicToken' => 'x', 'PasswdHash' => 'h']);
        $this->assertSame(['IdInvoice' => 1], $one);
    }

    public function testServiceEmittedWithoutTheListKeepsCredentialOnlyPolicy(): void
    {
        $api = new Api('invoice', null, null);
        $this->assertSame([], $api->getSecretColumns());
        $out = $this->call($api, 'stripSensitiveOutput', ['IdInvoice' => 1, 'PublicToken' => 'x', 'PasswdHash' => 'h']);
        $this->assertSame(['IdInvoice' => 1, 'PublicToken' => 'x'], $out);
        $this->assertNull($this->call($api, 'secretQueryRef', ['query' => ['filter' => ['Invoice' => [['public_token', '%']]]]]));
    }

    /** @dataProvider secretQueries */
    public function testQueriesNamingASecretColumnAreRefused(array $request): void
    {
        $api = new Api('campaign', null, null, ['event_secret']);
        $this->assertNotNull($this->call($api, 'secretQueryRef', $request));
        // Refused before authorize() / the query ever runs.
        $ret = $api->getJson($request + ['rbac_public' => '']);
        $this->assertSame('failure', $ret['status']);
        $this->assertStringContainsString('not queryable', (string) $ret['error']);
        $this->assertSame('failure', $api->getOneJson($request + ['rbac_public' => ''])['status']);
        $this->assertSame('failure', $api->deleteJson($request)['status']);
    }

    public static function secretQueries(): array
    {
        $q = static fn (array $query) => [['query' => $query]];
        return [
            'filter snake'       => $q(['filter' => ['Campaign' => [['event_secret', 'a%']]]]),
            'filter single row'  => $q(['filter' => ['Campaign' => ['EventSecret', 'a%']]]),
            'filter json string' => $q(['filter' => ['Campaign' => '[["eventSecret","a%"]]']]),
            'filter dotted'      => $q(['filter' => ['Campaign' => [['Campaign.event_secret', 'x']]]]),
            'select plain'       => $q(['select' => ['Name', 'EventSecret']]),
            'select aliased'     => $q(['select' => [['event_secret', 'x']]]),
            'select json string' => $q(['select' => '["event-secret"]']),
            'select aggregate'   => $q(['select' => [['MAX(event_secret)', 'm']]]),
            'order'              => $q(['order' => [['event_secret', 'asc']]]),
            'groupby'            => $q(['groupby' => ['Campaign.EventSecret']]),
            'mcp normalized'     => [['normalized_query' => ['filter' => ['Campaign' => [['event_secret', '%']]]]]],
            'write data.query'   => [['data' => ['query' => ['filter' => ['Campaign' => [['event_secret', '%']]]]]]],
        ];
    }

    public function testOrdinaryQueriesPass(): void
    {
        $api = new Api('campaign', null, null, ['event_secret']);
        $this->assertNull($this->call($api, 'secretQueryRef', ['query' => [
            'select' => ['Name', ['COUNT(IdCampaign)', 'n']],
            'filter' => ['Campaign' => [['name', '%x%'], ['status', 'Sent', 'ne']]],
            'order'  => [['name', 'asc']],
        ]]));
        $this->assertNull($this->call($api, 'secretQueryRef', ['query' => []]));
    }

    public function testQueryDrivenUpdateOnSecretIsRefused(): void
    {
        $api = new Api('campaign', null, null, ['event_secret']);
        $ret = $api->setJson(['data' => ['name' => 'x', 'query' => ['filter' => ['Campaign' => [['event_secret', 'a%']]]]], 'action' => 'update']);
        $this->assertSame('failure', $ret['status']);
        $this->assertStringContainsString('not queryable', (string) $ret['error']);
    }
}
