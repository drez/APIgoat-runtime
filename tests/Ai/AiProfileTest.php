<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\AiConfig;
use ApiGoat\Ai\AiManifest;
use ApiGoat\Ai\AiProfile;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../src/Ai/AiConfig.php';
require_once __DIR__ . '/../../src/Ai/AiProfile.php';

final class AiProfileTest extends TestCase
{
    private const ENV = ['OLLAMA_BASE_URL', 'OLLAMA_API_KEY', 'OLLAMA_MODEL', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'OPENAI_MODEL', 'OLLAMA_CHAT_MODEL', 'OPENAI_CHAT_MODEL'];

    protected function setUp(): void
    {
        foreach (self::ENV as $n) {
            \putenv($n);
            unset($_ENV[$n]);
        }
        AiManifest::reset();
        AiConfig::reset();
        AiProfile::setResolver(null);
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    public function testNoResolverIsOllamaOnManifestDefaults(): void
    {
        $p = AiProfile::forTenant(null);
        self::assertSame('ollama', $p->provider());
        self::assertSame(AiManifest::baseUrl(), $p->baseUrl());
        self::assertSame('ollama', $p->apiKey());
        self::assertSame('bearer', $p->auth());
        self::assertSame(AiManifest::timeout(), $p->timeout());
        self::assertSame(AiManifest::retries(), $p->retries());
        self::assertSame(AiManifest::throttleSeconds(), $p->throttle());
        self::assertSame(['input_per_m' => 0.0, 'output_per_m' => 0.0], $p->prices());
        self::assertSame('none', $p->fallbackPolicy());
        self::assertSame('v1', $p->promptVersion());
        self::assertFalse($p->isFallback());
        self::assertNull($p->cloudFallback());
    }

    public function testEnvLadderForOllama(): void
    {
        \putenv('OLLAMA_BASE_URL=http://10.0.0.5:11434/v1');
        \putenv('OLLAMA_API_KEY=lan-token');
        \putenv('OLLAMA_MODEL=gm-triage:v1');
        $p = AiProfile::forTenant(7);
        self::assertSame('http://10.0.0.5:11434/v1', $p->baseUrl());
        self::assertSame('lan-token', $p->apiKey());
        self::assertSame('gm-triage:v1', $p->model());
    }

    public function testOllamaNeverUsesOperatorOpenAiKey(): void
    {
        \putenv('OPENAI_API_KEY=sk-operator');
        self::assertSame('sk-operator', AiConfig::apiKey(), 'precondition');
        $p = AiProfile::forTenant(1);
        self::assertSame('ollama', $p->provider());
        self::assertSame('ollama', $p->apiKey());
        self::assertStringNotContainsString('sk-operator', \json_encode($p->gatewayOpts()));
    }

    public function testResolverWinsOverEnvAndMemoizesPerTenant(): void
    {
        \putenv('OLLAMA_BASE_URL=http://env/v1');
        $calls = 0;
        AiProfile::setResolver(function (?int $id) use (&$calls): array {
            $calls++;
            if ($id === 2) {
                return ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-company',
                    'prices' => ['input_per_m' => '0.15', 'output_per_m' => 0.6], 'timeout' => 90];
            }

            return ['base_url' => 'http://resolver/v1', 'prompt_version' => 'v3'];
        });

        $a = AiProfile::forTenant(1);
        self::assertSame('http://resolver/v1', $a->baseUrl());
        self::assertSame('v3', $a->promptVersion());
        self::assertSame($a, AiProfile::forTenant(1), 'memoized');
        self::assertSame(1, $calls);

        $b = AiProfile::forTenant(2);
        self::assertSame('openai', $b->provider());
        self::assertSame('https://api.openai.com/v1', $b->baseUrl());
        self::assertSame('sk-company', $b->apiKey());
        self::assertSame(['input_per_m' => 0.15, 'output_per_m' => 0.6], $b->prices());
        self::assertSame(90, $b->timeout());
        self::assertSame(2, $calls);

        $n = AiProfile::forTenant(null);
        self::assertSame(3, $calls);
        self::assertSame($n, AiProfile::forTenant(null), 'null tenant memoized too');

        AiProfile::reset();
        AiProfile::forTenant(1);
        self::assertSame(4, $calls, 'reset() drops the memo');
    }

    public function testGatewayOptsShape(): void
    {
        AiProfile::setResolver(fn () => ['base_url' => 'http://box/v1', 'api_key' => 'k', 'throttle' => 0, 'retries' => 1, 'timeout' => 120]);
        self::assertSame(
            ['base_url' => 'http://box/v1', 'api_key' => 'k', 'auth' => 'bearer', 'timeout' => 120, 'retries' => 1, 'throttle' => 0.0],
            AiProfile::forTenant(3)->gatewayOpts()
        );
    }

    public function testAnthropicDefaultsToXApiKey(): void
    {
        \putenv('ANTHROPIC_API_KEY=sk-ant');
        AiProfile::setResolver(fn () => ['provider' => 'anthropic']);
        $p = AiProfile::forTenant(1);
        self::assertSame('x-api-key', $p->auth());
        self::assertSame('https://api.anthropic.com/v1', $p->baseUrl());
        self::assertSame('sk-ant', $p->apiKey());
    }

    public function testUnknownProviderAndPolicyFallToSafeDefaults(): void
    {
        AiProfile::setResolver(fn () => ['provider' => 'gemini', 'fallback_policy' => 'always', 'auth' => 'magic']);
        $p = AiProfile::forTenant(1);
        self::assertSame('ollama', $p->provider());
        self::assertSame('none', $p->fallbackPolicy());
        self::assertSame('bearer', $p->auth());
    }

    public function testFallbackRequiresPolicyAndCompanyKey(): void
    {
        \putenv('OPENAI_API_KEY=sk-operator');

        // policy off, even with a company key → no fallback
        AiProfile::setResolver(fn () => ['fallback' => ['api_key' => 'sk-company']]);
        self::assertNull(AiProfile::forTenant(1)->cloudFallback());

        // policy on, no company key → no fallback, and the operator key is NOT borrowed
        AiProfile::setResolver(fn () => ['fallback_policy' => 'cloud_if_configured', 'fallback' => ['provider' => 'openai']]);
        self::assertNull(AiProfile::forTenant(1)->cloudFallback());

        // policy on, company key present → cloud profile flagged fallback
        AiProfile::setResolver(fn () => [
            'fallback_policy' => 'cloud_if_configured',
            'timeout' => 45, 'prompt_version' => 'v9',
            'fallback' => ['api_key' => 'sk-company', 'model' => 'gpt-4o-mini', 'prices' => ['input_per_m' => 2.5, 'output_per_m' => 10]],
        ]);
        $primary = AiProfile::forTenant(1);
        $fb = $primary->cloudFallback();
        self::assertNotNull($fb);
        self::assertTrue($fb->isFallback());
        self::assertSame('openai', $fb->provider());
        self::assertSame('sk-company', $fb->apiKey());
        self::assertSame('https://api.openai.com/v1', $fb->baseUrl());
        self::assertSame('gpt-4o-mini', $fb->model());
        self::assertSame(45, $fb->timeout(), 'inherits primary timeout');
        self::assertSame('v9', $fb->promptVersion());
        self::assertSame(['input_per_m' => 2.5, 'output_per_m' => 10.0], $fb->prices());
        self::assertNull($fb->cloudFallback(), 'a fallback has no fallback');
        self::assertSame($fb->cloudFallback(), $primary->withFallback()?->cloudFallback());

        // a "cloud" fallback onto another ollama box is refused
        AiProfile::setResolver(fn () => ['fallback_policy' => 'cloud_if_configured', 'fallback' => ['provider' => 'ollama', 'api_key' => 'x']]);
        self::assertNull(AiProfile::forTenant(1)->cloudFallback());
    }

    public function testChatModelLadderForOllamaDefaultsToHermesNeverTheTriageModel(): void
    {
        \putenv('OLLAMA_MODEL=gm-triage:v1');
        $p = AiProfile::forTenant(1);
        self::assertSame('gm-triage:v1', $p->model());
        self::assertSame(AiProfile::DEFAULT_OLLAMA_CHAT_MODEL, $p->chatModel());
        self::assertSame('hermes3:8b', $p->chatModel());

        AiProfile::reset();
        \putenv('OLLAMA_CHAT_MODEL=llama3.1:8b');
        self::assertSame('llama3.1:8b', AiProfile::forTenant(1)->chatModel(), 'env beats the default');

        AiProfile::setResolver(fn () => ['model' => 'gm-triage:v1', 'chat_model' => 'hermes3:70b']);
        self::assertSame('hermes3:70b', AiProfile::forTenant(1)->chatModel(), 'resolver beats env');
        self::assertSame('gm-triage:v1', AiProfile::forTenant(1)->model());
    }

    public function testChatModelForCloudProviderFallsBackToModel(): void
    {
        AiProfile::setResolver(fn () => ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'k']);
        self::assertSame('gpt-4o-mini', AiProfile::forTenant(2)->chatModel());

        AiProfile::setResolver(fn () => ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'chat_model' => 'gpt-4o', 'api_key' => 'k']);
        self::assertSame('gpt-4o', AiProfile::forTenant(2)->chatModel());
    }
}
