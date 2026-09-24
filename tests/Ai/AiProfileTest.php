<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\AiConfig;
use ApiGoat\Ai\AiManifest;
use ApiGoat\Ai\AiProfile;
use ApiGoat\Tests\Ai\Support\ManifestFixture;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../src/Ai/AiConfig.php';
require_once __DIR__ . '/../../src/Ai/AiProfile.php';
require_once __DIR__ . '/support/ManifestFixture.php';

final class AiProfileTest extends TestCase
{
    private const ENV = ['OLLAMA_BASE_URL', 'OLLAMA_API_KEY', 'OLLAMA_MODEL', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'OPENAI_MODEL', 'OLLAMA_CHAT_MODEL', 'OPENAI_CHAT_MODEL', 'OLLAMA_EMBED_MODEL', 'OPENAI_EMBED_MODEL'];

    protected function setUp(): void
    {
        foreach (self::ENV as $n) {
            \putenv($n);
            unset($_ENV[$n]);
        }
        ManifestFixture::clear();
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

    public function testOperatorCloudKeyNeverRidesToAResolverChosenHost(): void
    {
        \putenv('OPENAI_API_KEY=sk-operator');
        \putenv('ANTHROPIC_API_KEY=sk-ant-operator');
        AiProfile::setResolver(fn ($t) => match ($t) {
            1 => ['provider' => 'openai', 'base_url' => 'https://evil.example/v1'],
            2 => ['provider' => 'anthropic', 'base_url' => 'https://evil.example/v1'],
            3 => ['provider' => 'openai', 'base_url' => 'https://api.openai.com/v1/'],
            4 => ['provider' => 'openai'],
            5 => ['provider' => 'openai', 'base_url' => 'https://proxy.example/v1', 'api_key' => 'sk-own'],
        });
        self::assertSame('', AiProfile::forTenant(1)->apiKey(), 'openai key withheld from a custom host');
        self::assertSame('', AiProfile::forTenant(2)->apiKey(), 'anthropic key withheld from a custom host');
        self::assertSame('sk-operator', AiProfile::forTenant(3)->apiKey(), 'the provider\'s own endpoint still gets it');
        self::assertSame('sk-operator', AiProfile::forTenant(4)->apiKey(), 'default endpoint still gets it');
        self::assertSame('sk-own', AiProfile::forTenant(5)->apiKey(), 'an explicit key is used as-is');
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

    /* ── chatModel(): the six-rung ladder ─────────────────────────────── */

    /** Rung 1 — the resolver's chat_model beats everything below it. */
    public function testChatModelRung1ResolverWins(): void
    {
        ManifestFixture::write(self::manifestWithChat(['llm_model' => 'from-manifest']));
        \putenv('OLLAMA_CHAT_MODEL=from-env');
        AiProfile::setResolver(fn () => ['model' => 'gm-triage:v3', 'chat_model' => 'hermes3:70b']);

        self::assertSame('hermes3:70b', AiProfile::forTenant(1)->chatModel());
        self::assertSame('gm-triage:v3', AiProfile::forTenant(1)->model(), 'the task model is untouched');
    }

    /** Rung 3 — env, still above everything new. (Rung 2, the config row, needs an ORM.) */
    public function testChatModelRung3EnvBeatsTheManifestAndTheTaskModel(): void
    {
        ManifestFixture::write(self::manifestWithChat(['llm_model' => 'from-manifest']));
        \putenv('OLLAMA_MODEL=gm-triage:v3');
        \putenv('OLLAMA_CHAT_MODEL=llama3.1:8b');

        self::assertSame('llama3.1:8b', AiProfile::forTenant(1)->chatModel());
    }

    /** Rung 4 — NEW: declared in HJSON as chat.llm_model. */
    public function testChatModelRung4ManifestLlmModel(): void
    {
        ManifestFixture::write(self::manifestWithChat(['llm_model' => 'qwen3.5:9b']));
        \putenv('OLLAMA_MODEL=gm-triage:v3');

        self::assertSame('qwen3.5:9b', AiProfile::forTenant(1)->chatModel());
        self::assertSame('gm-triage:v3', AiProfile::forTenant(1)->model());
    }

    /**
     * Rung 4 is skipped for a cloud FALLBACK profile: an Ollama tag is not a
     * valid OpenAI model name, and inheriting one would 404 the fallback at
     * the exact moment the local box is already down.
     */
    public function testChatModelRung4IsSkippedForAFallbackProfile(): void
    {
        ManifestFixture::write(self::manifestWithChat(['llm_model' => 'gm-triage:v3']));
        AiProfile::setResolver(fn () => [
            'fallback_policy' => 'cloud_if_configured',
            'fallback' => ['api_key' => 'sk-company', 'model' => 'gpt-4o-mini'],
        ]);

        $fb = AiProfile::forTenant(1)->cloudFallback();
        self::assertNotNull($fb);
        self::assertTrue($fb->isFallback());
        self::assertSame('gpt-4o-mini', $fb->chatModel(), 'the fallback keeps its own model');
    }

    /**
     * chat.model is the Propel PhpName of the table the endpoint lands on —
     * "MailMessage" — and must NEVER be read as an LLM name.
     */
    public function testChatManifestModelIsNeverReadAsAnLlm(): void
    {
        ManifestFixture::write(self::manifestWithChat([])); // model => MailMessage, no llm_model
        \putenv('OLLAMA_MODEL=gm-triage:v3');

        self::assertSame('MailMessage', AiManifest::chat()['model'], 'precondition');
        self::assertSame('gm-triage:v3', AiProfile::forTenant(1)->chatModel());
    }

    /**
     * Rung 5 — NEW: reuse the task model. On a single-slot host any distinct
     * chat tag evicts the pinned triage model on every question.
     */
    public function testChatModelRung5ReusesTheTaskModel(): void
    {
        \putenv('OLLAMA_MODEL=gm-triage:v3');

        self::assertSame('gm-triage:v3', AiProfile::forTenant(1)->chatModel());
    }

    /**
     * Rung 6 — the terminal default is still DEFAULT_OLLAMA_CHAT_MODEL, and
     * an EMPTY task model reaches it (str() returns null on '', so the ??
     * chain does not stop at rung 5).
     */
    public function testChatModelRung6TerminalDefaultOnAnEmptyTaskModel(): void
    {
        $p = AiProfile::forTenant(1);
        self::assertSame('', $p->model(), 'precondition: nothing names a task model');
        self::assertSame(AiProfile::DEFAULT_OLLAMA_CHAT_MODEL, $p->chatModel());
        self::assertSame('hermes3:8b', $p->chatModel());

        AiProfile::reset();
        AiProfile::setResolver(fn () => ['model' => '   ']);
        self::assertSame('hermes3:8b', AiProfile::forTenant(1)->chatModel(), 'blank is not a model');
    }

    public function testChatModelForCloudProviderFallsBackToModel(): void
    {
        AiProfile::setResolver(fn () => ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'k']);
        self::assertSame('gpt-4o-mini', AiProfile::forTenant(2)->chatModel());

        AiProfile::setResolver(fn () => ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'chat_model' => 'gpt-4o', 'api_key' => 'k']);
        self::assertSame('gpt-4o', AiProfile::forTenant(2)->chatModel());
    }

    /* ── embedModel() / embedDimensions() ─────────────────────────────── */

    public function testEmbedIsEmptyWhenNothingDeclaresIt(): void
    {
        $p = AiProfile::forTenant(1);
        self::assertSame('', $p->embedModel());
        self::assertSame(0, $p->embedDimensions());
    }

    public function testEmbedLadderResolverThenEnvThenManifest(): void
    {
        ManifestFixture::write(self::manifest(['embed' => ['model' => 'embeddinggemma:300m', 'dimensions' => 768]]));
        $p = AiProfile::forTenant(1);
        self::assertSame('embeddinggemma:300m', $p->embedModel());
        self::assertSame(768, $p->embedDimensions());

        AiProfile::reset();
        \putenv('OLLAMA_EMBED_MODEL=from-env');
        self::assertSame('from-env', AiProfile::forTenant(1)->embedModel(), 'env beats the manifest');

        AiProfile::setResolver(fn () => ['embed_model' => 'from-resolver', 'embed_dimensions' => 1024]);
        self::assertSame('from-resolver', AiProfile::forTenant(1)->embedModel(), 'resolver beats env');
        self::assertSame(1024, AiProfile::forTenant(1)->embedDimensions());
    }

    public function testEmbedModelIsNotInheritedByACloudFallback(): void
    {
        ManifestFixture::write(self::manifest(['embed' => ['model' => 'embeddinggemma:300m', 'dimensions' => 768]]));
        AiProfile::setResolver(fn () => [
            'fallback_policy' => 'cloud_if_configured',
            'fallback' => ['api_key' => 'sk-company'],
        ]);

        self::assertSame('', AiProfile::forTenant(1)->cloudFallback()->embedModel());
    }

    /* ── keepAlive(): the pin ─────────────────────────────────────────── */

    public function testKeepAliveDefaultsToForeverOnAPrimaryOllamaProfile(): void
    {
        self::assertSame(-1, AiProfile::forTenant(1)->keepAlive());
    }

    public function testKeepAliveIsNullForCloudProvidersAndFallbacks(): void
    {
        AiProfile::setResolver(fn () => ['provider' => 'openai', 'api_key' => 'k']);
        self::assertNull(AiProfile::forTenant(1)->keepAlive());

        AiProfile::setResolver(fn () => [
            'fallback_policy' => 'cloud_if_configured',
            'fallback' => ['api_key' => 'sk-company'],
        ]);
        self::assertNull(AiProfile::forTenant(1)->cloudFallback()->keepAlive());
    }

    public function testKeepAliveComesFromTheManifestThenTheResolver(): void
    {
        ManifestFixture::write(self::manifest(['keep_alive' => '30m']));
        self::assertSame('30m', AiProfile::forTenant(1)->keepAlive());

        AiProfile::setResolver(fn () => ['keep_alive' => 0]);
        self::assertSame(0, AiProfile::forTenant(1)->keepAlive(), '0 means "unload now", not "unset"');
    }

    /* ── fixtures ─────────────────────────────────────────────────────── */

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private static function manifest(array $extra = []): array
    {
        return \array_merge([
            'base_url'  => 'http://box:11434/v1',
            'timeout'   => 30,
            'retries'   => 2,
            'throttle'  => 0.25,
            'log_table' => 'ai_call_log',
        ], $extra);
    }

    /** @param array<string,mixed> $chat @return array<string,mixed> */
    private static function manifestWithChat(array $chat): array
    {
        return self::manifest(['chat' => \array_merge([
            'table'   => 'mail_message',
            'model'   => 'MailMessage',
            'label'   => 'Ask about my email',
            'persona' => '',
        ], $chat)]);
    }
}
