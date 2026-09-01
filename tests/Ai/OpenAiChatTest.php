<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\AiProfile;
use ApiGoat\Ai\Chat\OpenAiChat;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../src/Ai/AiConfig.php';
require_once __DIR__ . '/../../src/Ai/AiUsageLogger.php';
require_once __DIR__ . '/../../src/Ai/AiGateway.php';
require_once __DIR__ . '/../../src/Ai/AiProfile.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatDriver.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatResult.php';
require_once __DIR__ . '/../../src/Ai/Chat/OpenAiChat.php';

final class OpenAiChatTest extends TestCase
{
    private const SCHEMA = ['type' => 'object', 'properties' => ['category' => ['type' => 'string']], 'required' => ['category'], 'additionalProperties' => false];

    protected function setUp(): void
    {
        AiProfile::setResolver(fn () => ['base_url' => 'http://box:11434/v1', 'model' => 'gm-triage:v1', 'api_key' => 'k', 'timeout' => 120, 'retries' => 1, 'throttle' => 0]);
    }

    protected function tearDown(): void
    {
        AiProfile::setResolver(null);
    }

    public function testBodyShapingWithJsonSchema(): void
    {
        $msgs = [['role' => 'system', 'content' => 's'], ['role' => 'user', 'content' => 'u']];
        $body = OpenAiChat::buildBody(AiProfile::forTenant(1), $msgs, ['max_tokens' => 300, 'temperature' => 0, 'json_schema' => self::SCHEMA, 'json_schema_name' => 'triage']);
        self::assertSame('gm-triage:v1', $body['model']);
        self::assertSame($msgs, $body['messages']);
        self::assertSame(300, $body['max_tokens']);
        self::assertSame(0.0, $body['temperature']);
        self::assertSame(
            ['type' => 'json_schema', 'json_schema' => ['name' => 'triage', 'schema' => self::SCHEMA, 'strict' => true]],
            $body['response_format']
        );
        self::assertArrayNotHasKey('format', $body);
    }

    public function testPlainBodyAndOllamaNativeFormatFlag(): void
    {
        $plain = OpenAiChat::buildBody(AiProfile::forTenant(1), [['role' => 'user', 'content' => 'hi']]);
        self::assertSame(['model', 'messages'], \array_keys($plain));

        $native = OpenAiChat::buildBody(AiProfile::forTenant(1), [], ['json_schema' => self::SCHEMA, 'format' => true]);
        self::assertSame(self::SCHEMA, $native['format']);
        self::assertArrayNotHasKey('response_format', $native);
    }

    public function testCompleteThreadsProfileOptsAndParsesAnswer(): void
    {
        $seen = null;
        $driver = new OpenAiChat(function (string $path, array $body, array $opts) use (&$seen): array {
            $seen = [$path, $body, $opts];

            return [200, ['choices' => [['message' => ['role' => 'assistant', 'content' => '{"category":"invoice"}']]],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 6]]];
        });
        $r = $driver->complete(AiProfile::forTenant(1), [['role' => 'user', 'content' => 'x']], ['timeout' => 15, 'json_schema' => self::SCHEMA]);

        self::assertSame('/chat/completions', $seen[0]);
        self::assertSame('gm-triage:v1', $seen[1]['model']);
        self::assertSame('http://box:11434/v1', $seen[2]['base_url']);
        self::assertSame('bearer', $seen[2]['auth']);
        self::assertSame(15, $seen[2]['timeout'], 'per-call timeout overrides the profile');
        self::assertSame(1, $seen[2]['retries']);
        self::assertSame(0.0, $seen[2]['throttle']);

        self::assertTrue($r->ok());
        self::assertSame(['category' => 'invoice'], $r->decodeJson());
        self::assertTrue($r->jsonValid());
        self::assertSame(['input_tokens' => 40, 'output_tokens' => 6], $r->usage());
    }

    public function testTransportFailureParsesToNotOk(): void
    {
        $r = OpenAiChat::parseResponse(0, null, 3001);
        self::assertFalse($r->ok());
        self::assertNotSame('', $r->transportError());
        self::assertNull($r->text());
        self::assertFalse($r->jsonValid());

        $bad = OpenAiChat::parseResponse(200, ['choices' => [['message' => ['content' => 'I cannot do that']]]], 1);
        self::assertTrue($bad->ok());
        self::assertFalse($bad->jsonValid());
    }
}
