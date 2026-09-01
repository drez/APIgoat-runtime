<?php

namespace ApiGoat\Tests\Google;

use ApiGoat\Google\ClientFactory;
use ApiGoat\Google\ErrorMapper;
use ApiGoat\Google\JwtSigner;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;
use PHPUnit\Framework\TestCase;

final class ClientFactoryTest extends TestCase
{
    /** @var array<int,array<string,mixed>> */
    private array $calls = [];
    private object $signer;

    /** @param array<int,array{status:int, body:string, headers?:string}> $responses */
    private function client(array $responses = []): ClientFactory
    {
        $this->calls = [];
        $queue = $responses;
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$queue) {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            $r = array_shift($queue) ?? ['status' => 200, 'body' => '{}'];
            return $r + ['headers' => ''];
        };
        // A signer double: the real one needs an RSA key; token caching is what's under test here.
        $this->signer = new class ('sa@x', 'k') extends JwtSigner {
            public int $mints = 0;
            public function mintAccessToken(array $scopes, ?string $subject = null): array
            {
                $this->mints++;
                return ['access_token' => 'tok-' . $this->mints, 'expires_in' => 3600];
            }
        };
        return new ClientFactory($this->signer, $transport);
    }

    public function testTokenIsMintedOncePerSubjectScopeSetAndSentAsBearer(): void
    {
        $g = $this->client();
        $g->get('https://gmail.googleapis.com/x', ['s1'], 'a@b');
        $g->get('https://gmail.googleapis.com/y', ['s1'], 'a@b');
        $g->get('https://gmail.googleapis.com/z', ['s1'], 'other@b');
        $this->assertSame(2, $this->signer->mints, 'one mint per (subject, scopes)');
        $this->assertSame('Authorization: Bearer tok-1', $this->calls[0]['headers'][0]);
        $this->assertSame('Authorization: Bearer tok-1', $this->calls[1]['headers'][0]);
        $this->assertSame('Authorization: Bearer tok-2', $this->calls[2]['headers'][0]);
        $g->forgetToken(['s1'], 'a@b');
        $g->get('https://gmail.googleapis.com/x', ['s1'], 'a@b');
        $this->assertSame(3, $this->signer->mints, 'forgetToken() forces a re-mint');
    }

    public function testPostSendsJsonAndDecodesBody(): void
    {
        $g   = $this->client([['status' => 200, 'body' => '{"id":"m1"}']]);
        $out = $g->post('https://gmail.googleapis.com/send', ['raw' => 'abc'], ['s']);
        $this->assertSame(['id' => 'm1'], $out);
        $call = end($this->calls);
        $this->assertSame('POST', $call['method']);
        $this->assertSame('{"raw":"abc"}', $call['body']);
        $this->assertContains('Content-Type: application/json', $call['headers']);
    }

    public function testDeleteTreats404AsAlreadyGone(): void
    {
        $g = $this->client([['status' => 404, 'body' => '{"error":{"message":"nope"}}'], ['status' => 204, 'body' => '']]);
        $this->assertFalse($g->delete('https://x/1', ['s']));
        $this->assertTrue($g->delete('https://x/2', ['s']));
    }

    public function testGetRawReturnsBodyUndecoded(): void
    {
        $g = $this->client([['status' => 200, 'body' => '%PDF-1.4']]);
        $this->assertSame('%PDF-1.4', $g->getRaw('https://x/f?alt=media', ['s']));
    }

    /** @dataProvider statusMap */
    public function testErrorMapperTaxonomy(int $status, string $body, string $expected, int $code, string $headers = ''): void
    {
        try {
            ErrorMapper::fail('ctx', $status, $headers, $body);
            $this->fail('should throw');
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf($expected, $e);
            $this->assertSame($code, $e->getCode());
            $this->assertStringStartsWith('ctx', $e->getMessage());
        }
    }

    public static function statusMap(): array
    {
        return [
            '401'              => [401, '{"error":{"message":"Invalid Credentials"}}', AuthFailed::class, 401],
            '403 forbidden'    => [403, '{"error":{"message":"Insufficient Permission","errors":[{"reason":"insufficientPermissions"}]}}', AuthFailed::class, 403],
            '403 rate reason'  => [403, '{"error":{"errors":[{"reason":"userRateLimitExceeded"}]}}', RateLimited::class, 7, "HTTP/1.1 403\r\nRetry-After: 7\r\n"],
            '403 quota detail' => [403, '{"error":{"details":[{"reason":"quotaExceeded"}]}}', RateLimited::class, 0],
            '429'              => [429, '', RateLimited::class, 30, "Retry-After: 30\r\n"],
            '404'              => [404, '{}', TransientError::class, 404],
            '503'              => [503, 'Backend Error', TransientError::class, 503],
            '400'              => [400, '{"error":{"message":"Bad Request"}}', TransientError::class, 400],
        ];
    }

    public function testApiErrorsSurfaceThroughTheClientWithSubject(): void
    {
        $g = $this->client([['status' => 429, 'body' => '{}', 'headers' => "Retry-After: 12\r\n"]]);
        try {
            $g->get('https://x', ['s'], 'u@x');
            $this->fail('should throw');
        } catch (RateLimited $e) {
            $this->assertSame(12, $e->getCode());
            $this->assertStringContainsString('sub=u@x', $e->getMessage());
        }
    }
}
