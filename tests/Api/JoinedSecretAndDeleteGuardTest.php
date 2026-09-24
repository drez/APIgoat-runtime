<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Api;

use ApiGoat\Api\Api;
use PHPUnit\Framework\TestCase;

/**
 * 2026-09-24 review:
 *  - secretQueryRef()/stripSensitiveOutput() only knew the BASE model's secret
 *    columns, so a join / dotted ref ("Invoice.public_token") read or
 *    LIKE-probed another model's secret, and the output strip compared the
 *    whole "Rel.Col" key so it never matched.
 *  - deleteJson() kept deleting after QueryBuilder dropped a filter (messages
 *    non-empty) — the selection was wider than asked, wrong rows deleted.
 * Fakes only: no Propel, no database.
 */
final class JoinedSecretAndDeleteGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../src/Utility/Legacy/html_helper.php'; // \camelize()
        if (!\defined('_AUTH_VAR')) {
            \define('_AUTH_VAR', 'jsdg_auth');
        }
    }

    protected function tearDown(): void
    {
        unset($_SESSION[\_AUTH_VAR]);
    }

    private function call(Api $api, string $method, ...$args)
    {
        $m = new \ReflectionMethod($api, $method);
        $m->setAccessible(true);
        return $m->invoke($api, ...$args);
    }

    /** @dataProvider joinedSecretQueries */
    public function testJoinedModelSecretReferencesAreRefused(array $query): void
    {
        // Base model with NO secret list of its own (or an unrelated one).
        foreach ([new Api('client', null, null), new Api('client', null, null, ['portal_pin'])] as $api) {
            $this->assertNotNull($this->call($api, 'secretQueryRef', ['query' => $query]), json_encode($query));
        }
    }

    public static function joinedSecretQueries(): array
    {
        return [
            'select dotted'     => [['join' => ['Invoice'], 'select' => ['Name', 'Invoice.PublicToken']]],
            'select aliased'    => [['join' => [['Invoice', 'inv', 'left']], 'select' => [['inv.public_token', 't']]]],
            'select aggregate'  => [['select' => [['MAX(Invoice.public_token)', 'm']]]],
            'filter dotted'     => [['filter' => ['Client' => [['Invoice.public_token', 'a%']]]]],
            'filter json'       => [['filter' => ['Client' => '[["Campaign.event_secret","a%"]]']]],
            'order'             => [['order' => [['PushDevice.token', 'asc']]]],
            'groupby'           => [['groupby' => ['Invoice.PublicToken']]],
        ];
    }

    public function testRegisteredJoinedListIsEnforced(): void
    {
        // A secret whose name is not token/secret-like is caught once the
        // joined model's list is known (its Api was built / registered).
        new Api('learner', null, null, ['sin_number']);
        $api = new Api('course', null, null);
        $this->assertNotNull($this->call($api, 'secretQueryRef', ['query' => ['filter' => ['Course' => [['Learner.sin_number', '1%']]]]]));
        Api::registerSecretColumns('Vault', ['combination']);
        $this->assertNotNull($this->call($api, 'secretQueryRef', ['query' => ['select' => ['Vault.Combination']]]));
    }

    public function testOrdinaryJoinedAndBaseRefsPass(): void
    {
        $api = new Api('client', null, null);
        $this->assertNull($this->call($api, 'secretQueryRef', ['query' => [
            'join'   => ['Invoice'],
            'select' => ['Name', 'Invoice.Number', ['SUM(Invoice.total)', 's']],
            'filter' => ['Client' => [['Invoice.status', 'Paid'], ['name', '%x%']]],
            'order'  => [['Invoice.date', 'desc']],
        ]]));
        // The base model's own non-listed columns keep the credential-only policy
        // (a legacy service without a secret list is unchanged).
        $this->assertNull($this->call(new Api('invoice', null, null), 'secretQueryRef',
            ['query' => ['filter' => ['Invoice' => [['public_token', '%']]]]]));
    }

    public function testDottedOutputKeysAreStripped(): void
    {
        $api = new Api('client', null, null, ['portal_pin']);
        $rows = [[
            'Name' => 'A',
            'Invoice.Number' => 'I-1',
            'Invoice.PublicToken' => 'abc',
            'inv.public_token' => 'abc',
            'Client.portal_pin' => '1234',
            'AuthyRelatedByIdCreation.PasswdHash' => 'h',
        ]];
        $this->assertSame([['Name' => 'A', 'Invoice.Number' => 'I-1']], $this->call($api, 'stripSensitiveOutput', $rows));
    }

    public function testDeleteRefusesWhenQueryBuilderDroppedSomething(): void
    {
        $_SESSION[\_AUTH_VAR] = new class {
            public function isAdmin() { return true; }
            public function isRoot() { return true; }
            public function get($k) { return null; }
            public function hasRights($m = '', $r = '') { return true; }
        };
        $row = new class {
            public bool $deleted = false;
            public function getPrimaryKey() { return 7; }
            public function delete() { $this->deleted = true; }
            public function isDeleted() { return $this->deleted; }
        };
        $qb = new class([$row]) {
            public $debug = false;
            public function __construct(private array $rows) {}
            public function getDataObj() { return $this->rows; }
            public function getMessages() { return ['Filter: Field not found (nope) on (Widget)']; }
        };
        $ret = (new Api('widget', null, null))->deleteJson([], $qb);
        $this->assertSame('failure', $ret['status']);
        $this->assertFalse($row->deleted, 'no row may be deleted when a filter was dropped');

        $qbOk = new class([$row]) {
            public $debug = false;
            public function __construct(private array $rows) {}
            public function getDataObj() { return $this->rows; }
            public function getMessages() { return []; }
        };
        $ret = (new Api('widget', new \stdClass(), null))->deleteJson([], $qbOk);
        $this->assertSame('success', $ret['status']);
        $this->assertTrue($row->deleted);
    }

    public function testSecretNameFloorIsSegmentBased(): void
    {
        // Secrets the old substring floor missed (review: apichatbot key_hash).
        foreach (['key_hash', 'KeyHash', 'password_hash', 'access_key', 'refresh_key', 'secret_key', 'private_key',
                  'api_key', 'ApiKey', 'apikey', 'public_token', 'PublicToken', 'webhook_secret', 'passphrase', 'salt'] as $n) {
            $this->assertTrue(Api::isJoinedSecretColumn($n), $n);
        }
        // Ordinary columns the substring floor refused (usage counters etc.).
        foreach (['tokens', 'tokens_in', 'tokens_out', 'output_tokens', 'OutputTokens', 'seo_keywords',
                  'stripe_publishable_key', 'hash', 'monkey', 'keyword', 'title'] as $n) {
            $this->assertFalse(Api::isJoinedSecretColumn($n), $n);
        }
    }

    public function testSecretNameFloorCatchesRunTogetherAndPrefixedNames(): void
    {
        // Run-together names the segment floor missed (security re-check of 59d0d88).
        foreach (['passwordhash', 'userpassword', 'apitoken', 'authtoken', 'accesstoken', 'refreshtoken',
                  'totpsecret', 'password_last_changed', 'passwd', 'smtp_pwd', 'smtp_pass', 'db_passwd',
                  'hmac_key', 'signing_key', 'encryption_key', 'aws_key', 'google_maps_key', 'SigningKey',
                  'reset_token_hash', 'api_key_enc', 'id_token', 'sync_token', 'clientSecret'] as $n) {
            $this->assertTrue(Api::isSecretName($n), $n);
            $this->assertTrue(Api::isJoinedSecretColumn($n), $n);
        }
        // Quantities, metadata and foreign keys stay joinable (fleet schemas:
        // cryptobboy balances, apichatbot/apigmail usage, apigTutor pass marks).
        foreach (['token_count', 'monthly_token_limit', 'free_token', 'staked_token', 'total_token',
                  'flexible_token', 'freeze_token', 'id_api_key', 'llm_api_key_fingerprint',
                  'llm_api_key_rotated_at', 'reset_token_expires', 'assignment_pass_mark', 'last_pass_at',
                  'secretary', 'bypass', 'compass', 'passenger', 'keynote', 'api_key_document'] as $n) {
            $this->assertFalse(Api::isSecretName($n), $n);
        }
    }
}
