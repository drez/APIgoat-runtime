<?php

namespace ApiGoat\Tests\Google;

require_once __DIR__ . '/../Mail/FakeTokenSource.php';

use ApiGoat\Google\ClientFactory;
use ApiGoat\Google\PeopleClient;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;
use ApiGoat\Tests\Mail\FakeTokenSource;
use PHPUnit\Framework\TestCase;

/**
 * People API reader — the contacts half of the OAuth grant. Every test runs
 * against a fake transport: this class must never be able to reach Google
 * from a test run.
 */
final class PeopleClientTest extends TestCase
{
    /** @var array<int,array{method:string,url:string,headers:string[],body:?string}> */
    private array $calls = [];
    /** @var list<array{status:int, body:mixed, headers?:string}> */
    private array $responses = [];
    private FakeTokenSource $tokens;

    protected function setUp(): void
    {
        $this->calls     = [];
        $this->responses = [];
        $this->tokens    = new FakeTokenSource();
    }

    private function client(): PeopleClient
    {
        $http = function (string $method, string $url, array $headers, ?string $body) {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            $r = array_shift($this->responses) ?? ['status' => 500, 'body' => 'no canned response for ' . $url];
            if (!is_string($r['body'])) {
                $r['body'] = (string) json_encode($r['body']);
            }
            return $r + ['headers' => ''];
        };
        return new PeopleClient($this->tokens, $http);
    }

    private function person(string $resource, string $name, array $emails, ?string $org = null): array
    {
        $p = ['resourceName' => $resource, 'names' => [['displayName' => $name]]];
        if ($emails !== []) {
            $p['emailAddresses'] = array_map(static fn ($e) => ['value' => $e], $emails);
        }
        if ($org !== null) {
            $p['organizations'] = [['name' => $org]];
        }
        return $p;
    }

    public function test_scope_constants_are_the_readonly_contacts_scopes(): void
    {
        $this->assertSame('https://www.googleapis.com/auth/contacts.readonly', ClientFactory::SCOPE_CONTACTS_READONLY);
        $this->assertSame('https://www.googleapis.com/auth/contacts.other.readonly', ClientFactory::SCOPE_CONTACTS_OTHER_READONLY);
    }

    public function test_connections_requests_the_documented_person_fields_and_normalises_a_person(): void
    {
        $this->responses[] = ['status' => 200, 'body' => [
            'connections' => [$this->person('people/c1', 'Ada Lovelace', ['Ada@Ferro.Example'], 'Ferro Inc')],
        ]];

        $out = $this->client()->connections();

        $this->assertCount(1, $this->calls);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertStringStartsWith('https://people.googleapis.com/v1/people/me/connections?', $this->calls[0]['url']);
        $this->assertStringContainsString('personFields=names%2CemailAddresses%2Corganizations', $this->calls[0]['url']);
        $this->assertStringContainsString('pageSize=1000', $this->calls[0]['url']);
        $this->assertStringNotContainsString('pageToken', $this->calls[0]['url']);
        $this->assertContains('Authorization: Bearer tok-1', $this->calls[0]['headers']);

        $this->assertNull($out['next']);
        $this->assertSame([[
            'resource' => 'people/c1',
            'name'     => 'Ada Lovelace',
            'emails'   => ['ada@ferro.example'],
            'org'      => 'Ferro Inc',
        ]], $out['people']);
    }

    public function test_other_contacts_uses_a_read_mask_and_has_no_organizations(): void
    {
        $this->responses[] = ['status' => 200, 'body' => [
            'otherContacts' => [$this->person('otherContacts/o1', 'Grace', ['grace@hopper.example'])],
        ]];

        $out = $this->client()->otherContacts();

        $this->assertStringStartsWith('https://people.googleapis.com/v1/otherContacts?', $this->calls[0]['url']);
        $this->assertStringContainsString('readMask=names%2CemailAddresses', $this->calls[0]['url']);
        $this->assertStringContainsString('pageSize=1000', $this->calls[0]['url']);
        $this->assertSame([[
            'resource' => 'otherContacts/o1',
            'name'     => 'Grace',
            'emails'   => ['grace@hopper.example'],
            'org'      => null,
        ]], $out['people']);
    }

    public function test_paging_follows_next_page_token_and_stops_when_it_is_absent(): void
    {
        $this->responses[] = ['status' => 200, 'body' => [
            'connections'   => [$this->person('people/c1', 'One', ['one@x.example'])],
            'nextPageToken' => 'PAGE-2',
        ]];
        $this->responses[] = ['status' => 200, 'body' => [
            'connections' => [$this->person('people/c2', 'Two', ['two@x.example'])],
        ]];

        $c     = $this->client();
        $first = $c->connections();
        $this->assertSame('PAGE-2', $first['next']);

        $second = $c->connections($first['next']);
        $this->assertNull($second['next'], 'no nextPageToken means the walk is over');
        $this->assertStringContainsString('pageToken=PAGE-2', $this->calls[1]['url']);
        $this->assertSame('people/c2', $second['people'][0]['resource']);
    }

    public function test_a_401_invalidates_the_token_and_retries_once_with_a_fresh_one(): void
    {
        $this->responses[] = ['status' => 401, 'body' => ['error' => ['message' => 'Invalid Credentials']]];
        $this->responses[] = ['status' => 200, 'body' => ['connections' => [$this->person('people/c1', 'Ada', ['ada@x.example'])]]];

        $out = $this->client()->connections();

        $this->assertCount(2, $this->calls);
        $this->assertSame(1, $this->tokens->invalidated);
        $this->assertContains('Authorization: Bearer tok-1', $this->calls[0]['headers']);
        $this->assertContains('Authorization: Bearer tok-2', $this->calls[1]['headers'], 'the retry carries a re-minted token');
        $this->assertSame('people/c1', $out['people'][0]['resource']);
    }

    public function test_a_second_401_is_auth_failed(): void
    {
        $this->responses[] = ['status' => 401, 'body' => ['error' => ['message' => 'Invalid Credentials']]];
        $this->responses[] = ['status' => 401, 'body' => ['error' => ['message' => 'Invalid Credentials']]];

        $this->expectException(AuthFailed::class);
        $this->client()->connections();
    }

    public function test_a_429_is_rate_limited(): void
    {
        $this->responses[] = ['status' => 429, 'headers' => "HTTP/1.1 429\r\nRetry-After: 42\r\n", 'body' => ['error' => ['message' => 'Quota exceeded']]];

        try {
            $this->client()->connections();
            $this->fail('expected RateLimited');
        } catch (RateLimited $e) {
            $this->assertSame(42, $e->getCode(), 'Retry-After becomes the exception code');
        }
    }

    public function test_a_500_is_a_transient_error(): void
    {
        $this->responses[] = ['status' => 503, 'body' => ['error' => ['message' => 'backend error']]];

        $this->expectException(TransientError::class);
        $this->client()->otherContacts();
    }

    public function test_a_person_with_two_addresses_yields_two_lowercased_emails(): void
    {
        $this->responses[] = ['status' => 200, 'body' => ['connections' => [
            $this->person('people/c1', 'Ada', ['Ada@Ferro.Example', ' ADA.L@other.example ']),
        ]]];

        $out = $this->client()->connections();
        $this->assertSame(['ada@ferro.example', 'ada.l@other.example'], $out['people'][0]['emails']);
    }

    public function test_a_person_with_no_usable_address_is_dropped(): void
    {
        $this->responses[] = ['status' => 200, 'body' => ['connections' => [
            ['resourceName' => 'people/no-mail', 'names' => [['displayName' => 'Phone Only']]],
            ['resourceName' => 'people/blank', 'emailAddresses' => [['value' => '  ']]],
            $this->person('people/c1', 'Ada', ['ada@x.example']),
        ]]];

        $out = $this->client()->connections();
        $this->assertSame(['people/c1'], array_column($out['people'], 'resource'));
    }

    public function test_a_person_with_no_name_keeps_a_null_name(): void
    {
        $this->responses[] = ['status' => 200, 'body' => ['connections' => [
            ['resourceName' => 'people/c1', 'emailAddresses' => [['value' => 'anon@x.example']]],
        ]]];

        $out = $this->client()->connections();
        $this->assertNull($out['people'][0]['name']);
        $this->assertSame(['anon@x.example'], $out['people'][0]['emails']);
    }

    public function test_an_empty_page_is_an_empty_people_list_not_an_error(): void
    {
        $this->responses[] = ['status' => 200, 'body' => []];

        $out = $this->client()->otherContacts();
        $this->assertSame([], $out['people']);
        $this->assertNull($out['next']);
    }
}
