<?php

use ApiGoat\Http\HaltResponse;
use ApiGoat\Services\Concerns\HaltsResponses;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;

// The runtime package declares no autoload section of its own; the test suite
// runs on a project's vendor/autoload.php, which maps ApiGoat\ to the INSTALLED
// clone, not this working tree.
require_once __DIR__ . '/../../src/Http/HaltResponse.php';
require_once __DIR__ . '/../../src/Services/Concerns/HaltsResponses.php';

/**
 * A40/C7 — halt() replaces die() in the generated services so short-circuit
 * responses still travel back out through the middleware stack.
 */
final class HaltResponseTest extends TestCase
{
    private function subject(): object
    {
        return new class {
            use HaltsResponses;

            /** @param array|string $body */
            public function callHalt($body, int $status = 200, array $headers = [], bool $json = false): void
            {
                $this->halt($body, $status, $headers, $json);
            }
        };
    }

    public function test_string_body_is_sent_verbatim_with_no_content_type(): void
    {
        try {
            $this->subject()->callHalt("alertb('a','b');");
            $this->fail('halt() must throw');
        } catch (HaltResponse $e) {
            $this->assertSame("alertb('a','b');", $e->getBodyText());
            $this->assertSame(200, $e->getStatusCode());
            $this->assertSame([], $e->getHaltHeaders());
        }
    }

    public function test_array_body_is_json_encoded_and_typed(): void
    {
        try {
            $this->subject()->callHalt(['status' => 'failure']);
            $this->fail('halt() must throw');
        } catch (HaltResponse $e) {
            $this->assertSame('{"status":"failure"}', $e->getBodyText());
            $this->assertSame('application/json;charset=UTF-8', $e->getHaltHeaders()['Content-Type']);
        }
    }

    public function test_preencoded_json_keeps_its_own_flags(): void
    {
        $payload = json_encode(['url' => 'a/b'], JSON_UNESCAPED_SLASHES);
        try {
            $this->subject()->callHalt($payload, 200, [], true);
            $this->fail('halt() must throw');
        } catch (HaltResponse $e) {
            $this->assertSame('{"url":"a/b"}', $e->getBodyText());
            $this->assertSame('application/json;charset=UTF-8', $e->getHaltHeaders()['Content-Type']);
        }
    }

    public function test_caller_supplied_content_type_wins(): void
    {
        try {
            $this->subject()->callHalt('{}', 200, ['content-type' => 'application/x-ndjson'], true);
            $this->fail('halt() must throw');
        } catch (HaltResponse $e) {
            $this->assertSame(['content-type' => 'application/x-ndjson'], $e->getHaltHeaders());
        }
    }

    public function test_applyTo_writes_status_headers_and_body(): void
    {
        $halt = new HaltResponse('{"a":1}', 404, ['Content-type' => 'application/json']);
        $out  = $halt->applyTo((new ResponseFactory())->createResponse());

        $this->assertSame(404, $out->getStatusCode());
        $this->assertSame('application/json', $out->getHeaderLine('Content-type'));
        $this->assertSame('{"a":1}', (string) $out->getBody());
    }

    public function test_applyTo_discards_a_partial_render_already_in_the_body(): void
    {
        $response = (new ResponseFactory())->createResponse();
        $response->getBody()->write('<html>half a page');

        $out = (new HaltResponse('STOP', 200))->applyTo($response);

        $this->assertSame('STOP', (string) $out->getBody());
    }
}
