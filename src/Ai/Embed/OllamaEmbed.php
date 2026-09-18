<?php

namespace ApiGoat\Ai\Embed;

use ApiGoat\Ai\AiGateway;
use ApiGoat\Ai\AiProfile;

/**
 * Embeddings through Ollama's OpenAI-compatible /v1 shim.
 *
 * Unlike chat, there is no reason to prefer the native endpoint here: the
 * shim's /embeddings is the same request and the same response, and nothing
 * about embedding needs a `think` flag. Using it keeps ONE parse path shared
 * with {@see OpenAiEmbed} and keeps AiUsageLogger's `/embeddings` → `embed`
 * mapping working with no schema change.
 *
 * Two contracts this driver enforces, both learned the hard way in
 * p/apichatbot's hand-written Embedder:
 *
 *   1. ORDER BY data[].index, never array order. The provider is allowed to
 *      return the objects in any order; the caller is writing each vector
 *      next to a specific row, so one transposition silently mislabels the
 *      whole batch and nothing ever errors.
 *   2. REJECT A WIDTH MISMATCH LOUDLY. The vector column is VECTOR(N); the
 *      day somebody changes the model tag but not the column, every write
 *      must fail at the first call rather than fill the corpus with
 *      unsearchable rows.
 *
 * It deliberately does NOT send `keep_alive`: the embedding model is meant to
 * age out on the daemon's default so it can never crowd out a pinned triage
 * model on a single-slot host.
 */
final class OllamaEmbed implements EmbedDriver
{
    public const PATH = '/embeddings';

    /** @var callable|null fn(string $path, array $body, array $opts): array{0:int,1:mixed} — test seam */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function embed(AiProfile $profile, array $texts, array $opts = []): EmbedResult
    {
        $body = self::buildBody($profile, $texts, $opts);

        $gw = $profile->gatewayOpts();
        if (isset($opts['timeout'])) {
            $gw['timeout'] = (int) $opts['timeout'];
        }

        $t0 = \microtime(true);
        $post = $this->transport ?? [AiGateway::class, 'post'];
        [$code, $decoded] = $post(self::PATH, $body, $gw);
        $ms = (int) \round((\microtime(true) - $t0) * 1000);

        $expected = isset($opts['dimensions']) ? (int) $opts['dimensions'] : $profile->embedDimensions();

        return self::parseResponse((int) $code, $decoded, $ms, \count($texts), $expected);
    }

    /**
     * The request body. Throws BEFORE any HTTP call when no embedding model
     * is configured — posting `model: ""` and parsing whatever comes back is
     * how a corpus gets embedded by the wrong thing.
     *
     * @param string[] $texts
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     * @throws EmbedFailed
     */
    public static function buildBody(AiProfile $profile, array $texts, array $opts = []): array
    {
        $model = isset($opts['model']) && \is_string($opts['model']) && $opts['model'] !== ''
            ? $opts['model'] : $profile->embedModel();
        if ($model === '') {
            throw new EmbedFailed(
                'no embedding model configured for provider ' . $profile->provider()
                . ' (declare with_ai.embed.model, or set <PROVIDER>_EMBED_MODEL)',
                0,
                false
            );
        }

        $body = [
            'model' => $model,
            'input' => \array_values(\array_map('strval', $texts)),
        ];

        // Same closed-body escape hatch as the chat drivers: `extra` may add
        // top-level keys but never overwrite what this method built.
        if (isset($opts['extra']) && \is_array($opts['extra'])) {
            foreach ($opts['extra'] as $k => $v) {
                if (!\array_key_exists($k, $body)) {
                    $body[$k] = $v;
                }
            }
        }

        return $body;
    }

    /**
     * Normalise an /embeddings answer into an EmbedResult, or throw.
     *
     * @param mixed $decoded
     * @param int $want how many vectors the caller asked for (0 = do not check)
     * @param int $expectedDims the declared width (0 = accept whatever comes back)
     * @throws EmbedFailed
     */
    public static function parseResponse(int $status, $decoded, int $latencyMs, int $want = 0, int $expectedDims = 0): EmbedResult
    {
        if ($status === 0) {
            throw new EmbedFailed('no HTTP response (transport error or timeout)', 0);
        }
        if ($status < 200 || $status >= 300) {
            throw new EmbedFailed('embeddings HTTP ' . $status . self::errorSuffix($decoded), $status);
        }
        if (!\is_array($decoded) || !\is_array($decoded['data'] ?? null)) {
            throw new EmbedFailed('embeddings response carried no data[]' . self::errorSuffix($decoded), $status, false);
        }

        // Order by data[].index — NEVER array order. Rows missing an index
        // keep their arrival position, so a provider that omits it still
        // round-trips.
        $rows = [];
        $pos = 0;
        foreach ($decoded['data'] as $row) {
            if (!\is_array($row) || !\is_array($row['embedding'] ?? null)) {
                continue;
            }
            $idx = isset($row['index']) && \is_numeric($row['index']) ? (int) $row['index'] : $pos;
            $rows[] = [$idx, $pos, \array_map('floatval', \array_values($row['embedding']))];
            $pos++;
        }
        \usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $vectors = \array_map(static fn (array $r): array => $r[2], $rows);

        if ($vectors === []) {
            throw new EmbedFailed('embeddings response carried no vectors', $status, false);
        }
        if ($want > 0 && \count($vectors) !== $want) {
            throw new EmbedFailed(
                'Embedding count ' . \count($vectors) . ' != requested ' . $want,
                $status,
                false
            );
        }
        if ($expectedDims > 0) {
            foreach ($vectors as $v) {
                if (\count($v) !== $expectedDims) {
                    throw new EmbedFailed(
                        'Embedding dim ' . \count($v) . ' != expected ' . $expectedDims . ' (model/column mismatch)',
                        $status,
                        false
                    );
                }
            }
        }

        return new EmbedResult(
            $status,
            $vectors,
            \is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
            $latencyMs,
            (string) ($decoded['model'] ?? ''),
            $decoded
        );
    }

    /** ": <what the provider said>", or ''. */
    private static function errorSuffix($decoded): string
    {
        if (!\is_array($decoded)) {
            return '';
        }
        $e = $decoded['error'] ?? null;
        if (\is_array($e)) {
            $e = $e['message'] ?? null;
        }

        return \is_string($e) && $e !== '' ? ': ' . $e : '';
    }
}
