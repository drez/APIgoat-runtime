<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\AiProfile;
use ApiGoat\Ai\Chat\OllamaChat;
use ApiGoat\Tests\Ai\Support\ManifestFixture;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../src/Ai/AiConfig.php';
require_once __DIR__ . '/../../src/Ai/AiUsageLogger.php';
require_once __DIR__ . '/../../src/Ai/AiGateway.php';
require_once __DIR__ . '/../../src/Ai/AiProfile.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatDriver.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatResult.php';
require_once __DIR__ . '/../../src/Ai/Chat/OllamaChat.php';
require_once __DIR__ . '/support/ManifestFixture.php';

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
        ManifestFixture::clear();
        AiProfile::setResolver(fn () => ['base_url' => 'http://box:11434/v1', 'model' => 'gm-triage:v3', 'api_key' => 'k', 'timeout' => 120, 'retries' => 1, 'throttle' => 0]);
    }

    protected function tearDown(): void
    {
        AiProfile::setResolver(null);
        ManifestFixture::clear();
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

    /* ── options passthrough ──────────────────────────────────────────── */

    /**
     * Without this, everything but temperature and num_predict comes from the
     * model's BAKED parameters — inherited from whatever base model the tag
     * was built FROM (gm-triage:v3 carries qwen3.5:9b's presence_penalty 1.5,
     * top_k 20, top_p 0.95), and silently changed by a model upgrade.
     */
    public function testCallerOptionsAreMergedIntoTheOptionsBlock(): void
    {
        $body = OllamaChat::buildBody(AiProfile::forTenant(1), [], [
            'temperature' => 0.2,
            'max_tokens'  => 600,
            'options'     => ['presence_penalty' => 0, 'top_k' => 40],
        ]);

        self::assertSame(
            ['temperature' => 0.2, 'num_predict' => 600, 'presence_penalty' => 0, 'top_k' => 40],
            $body['options']
        );
    }

    /** The caller wins on a collision — it is the one that knows this call. */
    public function testCallerOptionsWinOnACollision(): void
    {
        $body = OllamaChat::buildBody(AiProfile::forTenant(1), [], [
            'temperature' => 0.2,
            'max_tokens'  => 600,
            'options'     => ['temperature' => 0.9, 'num_predict' => 64],
        ]);

        self::assertSame(['temperature' => 0.9, 'num_predict' => 64], $body['options']);
    }

    /** Options alone still produce the block; a non-array key is ignored. */
    public function testOptionsAloneAndBadShapes(): void
    {
        $body = OllamaChat::buildBody(AiProfile::forTenant(1), [], ['options' => ['seed' => 7]]);
        self::assertSame(['seed' => 7], $body['options']);

        $ignored = OllamaChat::buildBody(AiProfile::forTenant(1), [], ['options' => 'nonsense']);
        self::assertArrayNotHasKey('options', $ignored);
    }

    /** Compat: no `options` key means the body is byte-identical to before. */
    public function testAbsentOptionsKeyLeavesTheBodyIdentical(): void
    {
        $p = AiProfile::forTenant(1);
        $with = OllamaChat::buildBody($p, [['role' => 'user', 'content' => 'hi']], ['temperature' => 0, 'max_tokens' => 512]);
        $without = OllamaChat::buildBody($p, [['role' => 'user', 'content' => 'hi']], ['temperature' => 0, 'max_tokens' => 512, 'options' => []]);

        self::assertSame($with, $without);
        self::assertSame(['temperature' => 0.0, 'num_predict' => 512], $with['options']);
    }

    /* ── keep_alive: the pin ──────────────────────────────────────────── */

    /**
     * Ollama's keep_alive is per REQUEST and RESETS the model's expiry, so a
     * chat request that omits it drops a model pinned with -1 down to the
     * daemon's 20-minute default — and the next triage pays a cold load.
     */
    public function testAPrimaryOllamaProfileCarriesTheForeverPin(): void
    {
        $body = OllamaChat::buildBody(AiProfile::forTenant(1), [['role' => 'user', 'content' => 'hi']]);

        self::assertSame(-1, $body['keep_alive']);
    }

    /** Compat: TriageService already sends -1 explicitly — its body is unchanged. */
    public function testAnExplicitExtraKeepAliveWins(): void
    {
        $body = OllamaChat::buildBody(AiProfile::forTenant(1), [], ['extra' => ['keep_alive' => '10m']]);

        self::assertSame('10m', $body['keep_alive']);

        $zero = OllamaChat::buildBody(AiProfile::forTenant(1), [], ['extra' => ['keep_alive' => 0]]);
        self::assertSame(0, $zero['keep_alive'], '0 means unload now, and must not be overwritten');
    }

    public function testTheProfileKeepAliveIsUsedWhenTheManifestNamesOne(): void
    {
        ManifestFixture::write(['base_url' => 'http://box:11434/v1', 'keep_alive' => '45m']);
        AiProfile::reset();

        self::assertSame('45m', OllamaChat::buildBody(AiProfile::forTenant(1), [])['keep_alive']);
    }

    /** A cloud profile adds none — keep_alive is an Ollama concept. */
    public function testACloudProfileAddsNoKeepAlive(): void
    {
        AiProfile::setResolver(fn () => ['provider' => 'openai', 'api_key' => 'k', 'model' => 'gpt-4o-mini']);

        self::assertArrayNotHasKey('keep_alive', OllamaChat::buildBody(AiProfile::forTenant(1), []));
    }
}
