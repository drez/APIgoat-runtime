<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai\Embed;

use ApiGoat\Ai\AiProfile;
use ApiGoat\Ai\AiUsageLogger;
use ApiGoat\Ai\Embed\EmbedFailed;
use ApiGoat\Ai\Embed\OllamaEmbed;
use ApiGoat\Ai\Embed\OpenAiEmbed;
use ApiGoat\Tests\Ai\Support\ManifestFixture;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../../src/Ai/AiConfig.php';
require_once __DIR__ . '/../../../src/Ai/AiUsageLogger.php';
require_once __DIR__ . '/../../../src/Ai/AiGateway.php';
require_once __DIR__ . '/../../../src/Ai/AiProfile.php';
require_once __DIR__ . '/../../../src/Ai/Embed/EmbedFailed.php';
require_once __DIR__ . '/../../../src/Ai/Embed/EmbedResult.php';
require_once __DIR__ . '/../../../src/Ai/Embed/EmbedDriver.php';
require_once __DIR__ . '/../../../src/Ai/Embed/OllamaEmbed.php';
require_once __DIR__ . '/../../../src/Ai/Embed/OpenAiEmbed.php';
require_once __DIR__ . '/../support/ManifestFixture.php';

/**
 * The embed driver, through its transport seam.
 *
 * Two of these tests are the whole reason the driver exists rather than a
 * fourth hand-written curl block: vectors are ordered by data[].index (a
 * transposed batch mislabels every row and never errors), and a width
 * mismatch is rejected loudly (the day the model tag changes but the
 * VECTOR(N) column does not).
 */
final class OllamaEmbedTest extends TestCase
{
    protected function setUp(): void
    {
        ManifestFixture::clear();
        AiProfile::setResolver(fn () => [
            'base_url' => 'http://box:11434/v1', 'api_key' => 'k',
            'model' => 'gm-triage:v3', 'embed_model' => 'embeddinggemma:300m',
            'embed_dimensions' => 4, 'timeout' => 60, 'retries' => 1, 'throttle' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        AiProfile::setResolver(null);
        ManifestFixture::clear();
    }

    /** @param array<int,array<int,float>> $vectors */
    private static function payload(array $vectors, ?array $order = null): array
    {
        $data = [];
        foreach ($vectors as $i => $v) {
            $data[] = ['object' => 'embedding', 'index' => $i, 'embedding' => $v];
        }
        if ($order !== null) {
            $shuffled = [];
            foreach ($order as $i) {
                $shuffled[] = $data[$i];
            }
            $data = $shuffled;
        }

        return ['object' => 'list', 'data' => $data, 'model' => 'embeddinggemma:300m',
            'usage' => ['prompt_tokens' => 11, 'total_tokens' => 11]];
    }

    public function testBodyShapeAndPath(): void
    {
        $seen = null;
        $driver = new OllamaEmbed(function (string $path, array $body, array $opts) use (&$seen): array {
            $seen = [$path, $body, $opts];

            return [200, self::payload([[0.1, 0.2, 0.3, 0.4]])];
        });

        $r = $driver->embed(AiProfile::forTenant(1), ['hello']);

        self::assertSame('/embeddings', $seen[0], 'the /v1 shim, shared with the cloud driver');
        self::assertSame('http://box:11434/v1', $seen[2]['base_url'], 'the /v1 suffix is NOT stripped here');
        self::assertSame(['model' => 'embeddinggemma:300m', 'input' => ['hello']], $seen[1]);
        self::assertTrue($r->ok());
        self::assertSame(4, $r->dimensions());
        self::assertSame([[0.1, 0.2, 0.3, 0.4]], $r->vectors());
        self::assertSame(['input_tokens' => 11, 'output_tokens' => 0], $r->usage());
        self::assertSame('embeddinggemma:300m', $r->model());
    }

    /** The embed model is meant to age out: it must never crowd out triage. */
    public function testNoKeepAliveIsEverSent(): void
    {
        ManifestFixture::write(['base_url' => 'http://box:11434/v1', 'keep_alive' => -1]);
        AiProfile::reset();

        $body = OllamaEmbed::buildBody(AiProfile::forTenant(1), ['x']);

        self::assertArrayNotHasKey('keep_alive', $body);
        self::assertSame(-1, AiProfile::forTenant(1)->keepAlive(), 'the profile still carries it — the driver just does not use it');
    }

    /** ORDER BY data[].index, never array order. */
    public function testVectorsAreReorderedByIndexNotByArrayOrder(): void
    {
        $vectors = [[1.0, 1.0], [2.0, 2.0], [3.0, 3.0]];
        $driver = new OllamaEmbed(fn (): array => [200, self::payload($vectors, [2, 0, 1])]);

        $r = $driver->embed(AiProfile::forTenant(1), ['a', 'b', 'c'], ['dimensions' => 2]);

        self::assertSame($vectors, $r->vectors());
        self::assertSame(3, $r->count());
    }

    /** A provider that omits `index` keeps arrival order rather than collapsing. */
    public function testRowsWithoutAnIndexKeepArrivalOrder(): void
    {
        $driver = new OllamaEmbed(fn (): array => [200, ['data' => [
            ['embedding' => [1.0, 1.0]],
            ['embedding' => [2.0, 2.0]],
        ]]]);

        $r = $driver->embed(AiProfile::forTenant(1), ['a', 'b'], ['dimensions' => 2]);

        self::assertSame([[1.0, 1.0], [2.0, 2.0]], $r->vectors());
    }

    /** The guard that catches "somebody changed the model tag but not the column". */
    public function testADimensionMismatchThrowsWithBothNumbers(): void
    {
        $driver = new OllamaEmbed(fn (): array => [200, self::payload([[0.1, 0.2, 0.3]])]);

        try {
            $driver->embed(AiProfile::forTenant(1), ['hello']); // profile declares 4
            self::fail('expected EmbedFailed');
        } catch (EmbedFailed $e) {
            self::assertSame('Embedding dim 3 != expected 4 (model/column mismatch)', $e->getMessage());
            self::assertFalse($e->transient(), 'a mismatch is never worth retrying');
        }
    }

    /** dimensions: 0 means "accept whatever comes back". */
    public function testNoDeclaredWidthAcceptsAnyVector(): void
    {
        $driver = new OllamaEmbed(fn (): array => [200, self::payload([[0.1, 0.2, 0.3]])]);

        $r = $driver->embed(AiProfile::forTenant(1), ['hello'], ['dimensions' => 0]);

        self::assertSame(3, $r->dimensions());
    }

    public function testACountMismatchThrows(): void
    {
        $driver = new OllamaEmbed(fn (): array => [200, self::payload([[1.0, 1.0]])]);

        $this->expectException(EmbedFailed::class);
        $this->expectExceptionMessage('Embedding count 1 != requested 2');
        $driver->embed(AiProfile::forTenant(1), ['a', 'b'], ['dimensions' => 2]);
    }

    /**
     * A backfill loop over tens of thousands of rows has to tell "the box is
     * busy" from "this will never work".
     */
    public function testTransientAndPermanentFailures(): void
    {
        foreach ([0 => true, 429 => true, 503 => true, 500 => true, 408 => true,
                  400 => false, 401 => false, 404 => false, 422 => false] as $status => $transient) {
            $driver = new OllamaEmbed(fn (): array => [$status, ['error' => ['message' => 'nope']]]);
            try {
                $driver->embed(AiProfile::forTenant(1), ['hello']);
                self::fail('expected EmbedFailed for HTTP ' . $status);
            } catch (EmbedFailed $e) {
                self::assertSame($status, $e->httpStatus(), 'status ' . $status);
                self::assertSame($transient, $e->transient(), 'transient for ' . $status);
            }
        }
    }

    public function testA404NamesWhatTheProviderSaid(): void
    {
        $driver = new OllamaEmbed(fn (): array => [404, ['error' => 'model "nope" not found']]);

        $this->expectException(EmbedFailed::class);
        $this->expectExceptionMessage('embeddings HTTP 404: model "nope" not found');
        $driver->embed(AiProfile::forTenant(1), ['hello']);
    }

    public function testA200WithoutVectorsIsAPermanentFailure(): void
    {
        foreach ([['data' => []], ['object' => 'list'], null] as $decoded) {
            $driver = new OllamaEmbed(fn (): array => [200, $decoded]);
            try {
                $driver->embed(AiProfile::forTenant(1), ['hello']);
                self::fail('expected EmbedFailed');
            } catch (EmbedFailed $e) {
                self::assertFalse($e->transient());
            }
        }
    }

    /**
     * Fail loudly rather than posting `model: ""` to Ollama and parsing
     * whatever comes back.
     */
    public function testAnUnconfiguredModelThrowsBeforeAnyHttpCall(): void
    {
        AiProfile::setResolver(fn () => ['base_url' => 'http://box:11434/v1', 'api_key' => 'k']);
        $posted = false;
        $driver = new OllamaEmbed(function () use (&$posted): array {
            $posted = true;

            return [200, []];
        });

        try {
            $driver->embed(AiProfile::forTenant(1), ['hello']);
            self::fail('expected EmbedFailed');
        } catch (EmbedFailed $e) {
            self::assertStringContainsString('no embedding model configured', $e->getMessage());
            self::assertFalse($e->transient());
        }
        self::assertFalse($posted, 'nothing may reach the wire');
    }

    public function testAnExplicitModelOptionOverridesTheProfile(): void
    {
        $body = OllamaEmbed::buildBody(AiProfile::forTenant(1), ['x'], ['model' => 'nomic-embed-text']);

        self::assertSame('nomic-embed-text', $body['model']);
    }

    /** `extra` may add top-level keys but never overwrite what the driver built. */
    public function testExtraCannotCorruptTheBody(): void
    {
        $body = OllamaEmbed::buildBody(AiProfile::forTenant(1), ['x'], [
            'extra' => ['model' => 'hijacked', 'input' => ['hijacked'], 'truncate' => false],
        ]);

        self::assertSame('embeddinggemma:300m', $body['model']);
        self::assertSame(['x'], $body['input']);
        self::assertFalse($body['truncate']);
    }

    /**
     * The usage log already carries the `embed` enum value and /embeddings
     * already maps to it, so logging needs no schema change; Ollama's NATIVE
     * path is the one arm that was missing.
     */
    public function testUsageLoggerMapsBothEmbeddingPathsToEmbed(): void
    {
        self::assertSame('embed', AiUsageLogger::operationFor('/embeddings'));
        self::assertSame('embed', AiUsageLogger::operationFor('/api/embed'));
        self::assertSame('embed', AiUsageLogger::operationFor('/api/embeddings'));
        self::assertSame('chat', AiUsageLogger::operationFor('/chat/completions'), 'unchanged');
        self::assertSame('other', AiUsageLogger::operationFor('/api/chat'), 'unchanged');
    }

    /** The cloud driver shares the parse path and adds OpenAI's `dimensions`. */
    public function testOpenAiEmbedAsksForTheDeclaredWidth(): void
    {
        $body = OpenAiEmbed::buildBody(AiProfile::forTenant(1), ['x']);

        self::assertSame(4, $body['dimensions']);
        self::assertArrayNotHasKey('dimensions', OllamaEmbed::buildBody(AiProfile::forTenant(1), ['x']));
    }
}
