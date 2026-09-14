<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\AiProfile;
use ApiGoat\Ai\Chat\OllamaChat;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../src/Ai/AiConfig.php';
require_once __DIR__ . '/../../src/Ai/AiUsageLogger.php';
require_once __DIR__ . '/../../src/Ai/AiGateway.php';
require_once __DIR__ . '/../../src/Ai/AiProfile.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatDriver.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatResult.php';
require_once __DIR__ . '/../../src/Ai/Chat/OllamaChat.php';

/**
 * Ollama's native /api/chat. It exists because reasoning cannot be turned
 * off through the /v1 shim — measured on qwen3.5:9b, same prompt and host:
 * /v1 with no flag and /v1 with chat_template_kwargs both timed out past
 * 240 s, /v1 with think:false took 222.8 s and 3,847 output tokens, and
 * /api/chat with think:false took 1.0 s and 14.
 */
final class OllamaChatTest extends TestCase
{
    private const SCHEMA = ['type' => 'object', 'properties' => ['category' => ['type' => 'string']], 'required' => ['category'], 'additionalProperties' => false];

    protected function setUp(): void
    {
        AiProfile::setResolver(fn () => ['base_url' => 'http://box:11434/v1', 'model' => 'gm-triage:v3', 'api_key' => 'k', 'timeout' => 120, 'retries' => 1, 'throttle' => 0]);
    }

    protected function tearDown(): void
    {
        AiProfile::setResolver(null);
    }

    /** The reason this driver exists: opt OUT explicitly, every request. */
    public function testThinkingIsDisabledByDefault(): void
    {
        $body = OllamaChat::buildBody(AiProfile::forTenant(1), [['role' => 'user', 'content' => 'hi']]);

        self::assertFalse($body['think']);
        self::assertFalse($body['stream']);
        self::assertSame('gm-triage:v3', $body['model']);
    }

    public function testThinkingCanBeAskedForExplicitly(): void
    {
        $body = OllamaChat::buildBody(AiProfile::forTenant(1), [], ['think' => true]);

        self::assertTrue($body['think']);
    }

    /** Sampling lives under `options`, and it is num_predict, not max_tokens. */
    public function testSamplingIsNestedUnderOptions(): void
    {
        $body = OllamaChat::buildBody(AiProfile::forTenant(1), [], ['temperature' => 0, 'max_tokens' => 512]);

        self::assertSame(['temperature' => 0.0, 'num_predict' => 512], $body['options']);
        self::assertArrayNotHasKey('max_tokens', $body);
    }

    /** Structured output is the bare schema, with no response_format envelope. */
    public function testJsonSchemaBecomesTheNativeFormatField(): void
    {
        $body = OllamaChat::buildBody(AiProfile::forTenant(1), [], ['json_schema' => self::SCHEMA]);

        self::assertSame(self::SCHEMA, $body['format']);
        self::assertArrayNotHasKey('response_format', $body);
    }

    /** The native API sits BESIDE /v1, not under it. */
    public function testTheNativeBaseDropsTheV1Suffix(): void
    {
        self::assertSame('http://box:11434', OllamaChat::nativeBase('http://box:11434/v1'));
        self::assertSame('http://box:11434', OllamaChat::nativeBase('http://box:11434/v1/'));
        self::assertSame('http://box:11434', OllamaChat::nativeBase('http://box:11434'));
    }

    public function testCompletePostsToTheNativePathWithTheStrippedBase(): void
    {
        $seen = null;
        $driver = new OllamaChat(function (string $path, array $body, array $opts) use (&$seen): array {
            $seen = [$path, $body, $opts];
            return [200, ['message' => ['content' => '{"category":"Inquiry"}'], 'prompt_eval_count' => 120, 'eval_count' => 14]];
        });

        $r = $driver->complete(AiProfile::forTenant(1), [['role' => 'user', 'content' => 'hi']], ['temperature' => 0]);

        self::assertSame('/api/chat', $seen[0]);
        self::assertSame('http://box:11434', $seen[2]['base_url'], 'the /v1 suffix must not reach the native path');
        self::assertFalse($seen[1]['think']);
        self::assertSame('{"category":"Inquiry"}', $r->text());
        self::assertSame(['input_tokens' => 120, 'output_tokens' => 14], $r->usage());
    }

    /** A caller cannot tell which endpoint answered. */
    public function testATransportFailureLooksLikeEveryOtherDriver(): void
    {
        $r = OllamaChat::parseResponse(0, null, 240000);

        self::assertNull($r->text());
        self::assertSame('no HTTP response (transport error or timeout)', $r->transportError());
    }

    public function testAnOllamaErrorPayloadIsSurfaced(): void
    {
        $r = OllamaChat::parseResponse(404, ['error' => 'model "nope" not found'], 12);

        self::assertSame('model "nope" not found', $r->transportError());
    }
}
