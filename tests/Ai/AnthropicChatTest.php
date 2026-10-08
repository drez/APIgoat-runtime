<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\AiProfile;
use ApiGoat\Ai\Chat\AnthropicChat;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../src/Ai/AiConfig.php';
require_once __DIR__ . '/../../src/Ai/AiUsageLogger.php';
require_once __DIR__ . '/../../src/Ai/AiGateway.php';
require_once __DIR__ . '/../../src/Ai/AiProfile.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatDriver.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatResult.php';
require_once __DIR__ . '/../../src/Ai/Chat/AnthropicChat.php';

final class AnthropicChatTest extends TestCase
{
    protected function tearDown(): void
    {
        AiProfile::setResolver(null);
    }

    public function testSystemIsHoistedAndTurnsKeepOrder(): void
    {
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'api_key' => 'k']);
        $body = AnthropicChat::buildBody($p, [
            ['role' => 'system', 'content' => 'You file mail.'],
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => '{"x":1}'],
            ['role' => 'system', 'content' => 'Second rule.'],
            ['role' => 'user', 'content' => 'fix it'],
        ], ['max_tokens' => 120, 'temperature' => 0]);
        $this->assertSame('claude-haiku-5-5', $body['model']);
        $this->assertSame("You file mail.\n\nSecond rule.", $body['system']);
        $this->assertSame([['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => '{"x":1}'], ['role' => 'user', 'content' => 'fix it']], $body['messages']);
        $this->assertSame(120, $body['max_tokens']);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertArrayNotHasKey('response_format', $body);
    }

    public function testMaxTokensIsAlwaysSent(): void
    {
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'api_key' => 'k']);
        $body = AnthropicChat::buildBody($p, [['role' => 'user', 'content' => 'x']], []);
        $this->assertSame(AnthropicChat::DEFAULT_MAX_TOKENS, $body['max_tokens']);
    }

    public function testJsonSchemaUsesOutputConfigFormat(): void
    {
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'api_key' => 'k']);
        $schema = ['type' => 'object', 'properties' => ['job' => ['type' => ['string', 'null']], 'confidence' => ['type' => 'number']], 'required' => ['job', 'confidence'], 'additionalProperties' => false];
        $body = AnthropicChat::buildBody($p, [['role' => 'user', 'content' => 'x']], ['json_schema' => $schema, 'json_schema_name' => 'job']);
        $this->assertSame(['format' => ['type' => 'json_schema', 'schema' => $schema]], $body['output_config']);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('tool_choice', $body);
    }

    public function testNeverSendsTemperatureEvenViaExtra(): void
    {
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'api_key' => 'k']);
        $body = AnthropicChat::buildBody($p, [['role' => 'user', 'content' => 'x']], ['temperature' => 0.7, 'extra' => ['temperature' => 0.2]]);
        $this->assertArrayNotHasKey('temperature', $body);
    }

    public function testSonnetGetsBetweenToolsAndKeepsFormat(): void
    {
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'api_key' => 'k']);
        $schema = ['type' => 'object'];
        $body = AnthropicChat::buildBody($p, [['role' => 'user', 'content' => 'x']], ['json_schema' => $schema]);
        $this->assertSame(['type' => 'between_tools'], $body['thinking']);
        $this->assertSame(['format' => ['type' => 'json_schema', 'schema' => $schema]], $body['output_config']);
        $plain = AnthropicChat::buildBody($p, [['role' => 'user', 'content' => 'x']], []);
        $this->assertSame(['type' => 'between_tools'], $plain['thinking']);
        $this->assertArrayNotHasKey('output_config', $plain);
    }

    public function testHaikuAndOtherModelsGetNoThinkingKeys(): void
    {
        $schema = ['type' => 'object'];
        foreach (['claude-haiku-5-5', 'claude-opus-4-1'] as $m) {
            $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => $m, 'api_key' => 'k']);
            $body = AnthropicChat::buildBody($p, [['role' => 'user', 'content' => 'x']], ['json_schema' => $schema]);
            $this->assertArrayNotHasKey('thinking', $body, $m);
            $this->assertSame(['format' => ['type' => 'json_schema', 'schema' => $schema]], $body['output_config'], $m);
        }
    }

    public function testRefusalAndTruncationAreFailures(): void
    {
        $refusal = AnthropicChat::parseResponse(200, ['content' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 0], 'stop_reason' => 'refusal'], 1);
        $this->assertFalse($refusal->ok());
        $this->assertSame('stop_reason: refusal', $refusal->transportError());
        $this->assertSame(200, $refusal->status());
        $cut = AnthropicChat::parseResponse(200, ['content' => [['type' => 'thinking', 'thinking' => '...']], 'stop_reason' => 'max_tokens'], 1);
        $this->assertFalse($cut->ok());
        $this->assertSame('stop_reason: max_tokens', $cut->transportError());
        $partial = AnthropicChat::parseResponse(200, ['content' => [['type' => 'text', 'text' => 'half a draf']], 'stop_reason' => 'max_tokens'], 1);
        $this->assertTrue($partial->ok());
        foreach (['end_turn', 'stop_sequence', 'tool_use'] as $r) {
            $this->assertTrue(AnthropicChat::parseResponse(200, ['content' => [['type' => 'text', 'text' => 'hi']], 'stop_reason' => $r], 1)->ok(), $r);
        }
    }

    public function testEmptyStringsNeverBecomeBlocksOrTurns(): void
    {
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'api_key' => 'k']);
        $body = AnthropicChat::buildBody($p, [
            ['role' => 'user', 'content' => 'q'],
            ['role' => 'assistant', 'content' => ''],
            ['role' => 'user', 'content' => 'fix it'],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => ''], ['type' => 'text', 'text' => 'more']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '']]],
        ], []);
        $this->assertSame([['role' => 'user', 'content' => [['type' => 'text', 'text' => "q\n\nfix it"], ['type' => 'text', 'text' => 'more']]]], $body['messages']);
        $m = AnthropicChat::buildBody($p, [['role' => 'user', 'content' => [['type' => 'image', 'source' => []]]], ['role' => 'user', 'content' => '']], []);
        $this->assertCount(1, $m['messages']);
    }

    public function testOpenAiExtrasAreDroppedAndOthersMerged(): void
    {
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'api_key' => 'k']);
        $body = AnthropicChat::buildBody($p, [['role' => 'user', 'content' => 'x']], ['max_tokens' => 5, 'extra' => ['max_completion_tokens' => 9, 'reasoning_effort' => 'low', 'metadata' => ['user_id' => 't1'], 'max_tokens' => 99]]);
        $this->assertSame(5, $body['max_tokens'], 'extra never overrides a built key');
        $this->assertArrayNotHasKey('max_completion_tokens', $body);
        $this->assertArrayNotHasKey('reasoning_effort', $body);
        $this->assertSame(['user_id' => 't1'], $body['metadata']);
    }

    public function testOpenAiImagePartsAreConvertedToAnthropicBlocks(): void
    {
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'api_key' => 'k']);
        $b64 = base64_encode('PNGDATA');
        $body = AnthropicChat::buildBody($p, [
            ['role' => 'system', 'content' => 'OCR'],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Transcribe.'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $b64]]]],
        ], ['max_tokens' => 10]);
        $this->assertSame([['type' => 'text', 'text' => 'Transcribe.'], ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $b64]]], $body['messages'][0]['content']);
    }

    public function testTextAnswerUsageAndErrorAreParsed(): void
    {
        $r = AnthropicChat::parseResponse(200, ['content' => [['type' => 'text', 'text' => 'Hello'], ['type' => 'text', 'text' => ' world']], 'usage' => ['input_tokens' => 40, 'output_tokens' => 6, 'cache_read_input_tokens' => 30], 'stop_reason' => 'end_turn'], 12);
        $this->assertSame([200, 'Hello world', ['input_tokens' => 40, 'output_tokens' => 6], 12], [$r->status(), $r->text(), $r->usage(), $r->latencyMs()]);
        $e = AnthropicChat::parseResponse(400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens: required']], 3);
        $this->assertFalse($e->ok());
        $this->assertSame('max_tokens: required', $e->raw()['error']['message']);
        $z = AnthropicChat::parseResponse(0, null, 3001);
        $this->assertSame('no HTTP response (transport error or timeout)', $z->transportError());
    }

    public function testToolUseIsOnlyAFallbackWhenThereIsNoText(): void
    {
        $r = AnthropicChat::parseResponse(200, ['content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'job', 'input' => ['job' => null, 'confidence' => 0.4]]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'stop_reason' => 'tool_use'], 1);
        $this->assertSame(['job' => null, 'confidence' => 0.4], $r->decodeJson());
        $this->assertSame('{"job":null,"confidence":0.4}', $r->text());
        $both = AnthropicChat::parseResponse(200, ['content' => [['type' => 'text', 'text' => '{"a":1}'], ['type' => 'tool_use', 'input' => ['b' => 2]]], 'stop_reason' => 'tool_use'], 1);
        $this->assertSame('{"a":1}', $both->text());
    }

    public function testCompleteThreadsProfileHeadersAndPath(): void
    {
        $seen = null;
        $driver = new AnthropicChat(function (string $path, array $body, array $opts) use (&$seen): array {
            $seen = [$path, $body, $opts];
            return [200, ['content' => [['type' => 'text', 'text' => 'ok']], 'usage' => ['input_tokens' => 2, 'output_tokens' => 1]]];
        });
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'api_key' => 'sk-ant', 'timeout' => 30, 'retries' => 0, 'throttle' => 0]);
        $r = $driver->complete($p, [['role' => 'user', 'content' => 'hi']], ['max_tokens' => 8, 'timeout' => 15]);
        $this->assertSame('/messages', $seen[0]);
        $this->assertSame('https://api.anthropic.com/v1', $seen[2]['base_url']);
        $this->assertSame('x-api-key', $seen[2]['auth']);
        $this->assertSame(['anthropic-version: 2023-06-01'], $seen[2]['headers']);
        $this->assertSame(15, $seen[2]['timeout']);
        $this->assertSame('ok', $r->text());
    }

    public function testConsecutiveSameRoleTurnsAreMerged(): void
    {
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'api_key' => 'k']);
        $body = AnthropicChat::buildBody($p, [
            ['role' => 'user', 'content' => 'a'], ['role' => 'user', 'content' => 'b'],
            ['role' => 'assistant', 'content' => 'c'], ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'd']]],
            ['role' => 'user', 'content' => 'e'],
        ], []);
        $this->assertSame([
            ['role' => 'user', 'content' => "a\n\nb"],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'c'], ['type' => 'text', 'text' => 'd']]],
            ['role' => 'user', 'content' => 'e'],
        ], $body['messages']);
    }

    public function testNonStringSystemIsSkippedAndTextBlocksJoined(): void
    {
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'api_key' => 'k']);
        $body = AnthropicChat::buildBody($p, [
            ['role' => 'system'], ['role' => 'system', 'content' => null],
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'A'], ['type' => 'text', 'text' => 'B']]],
            ['role' => 'user', 'content' => 'x'],
        ], []);
        $this->assertSame("A\n\nB", $body['system']);
        $none = AnthropicChat::buildBody($p, [['role' => 'system', 'content' => 5], ['role' => 'user', 'content' => 'x']], []);
        $this->assertArrayNotHasKey('system', $none);
    }

    public function testWrappedBase64IsStripped(): void
    {
        $p = AiProfile::fromSpec(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'api_key' => 'k']);
        $b64 = base64_encode('PNGDATA-PNGDATA');
        $wrapped = substr($b64, 0, 6) . "\r\n" . substr($b64, 6, 4) . " \n" . substr($b64, 10);
        $body = AnthropicChat::buildBody($p, [['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $wrapped]]]]], []);
        $this->assertSame($b64, $body['messages'][0]['content'][0]['source']['data']);
    }
}
