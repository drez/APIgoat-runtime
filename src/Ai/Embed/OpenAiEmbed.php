<?php

namespace ApiGoat\Ai\Embed;

use ApiGoat\Ai\AiGateway;
use ApiGoat\Ai\AiProfile;

/**
 * /embeddings against a cloud provider.
 *
 * Same wire shape as {@see OllamaEmbed} — the difference is the auth the
 * profile carries and OpenAI's optional `dimensions` request parameter, which
 * text-embedding-3-* honour (Matryoshka truncation) and which lets a project
 * fit a 3072-wide model into a VECTOR(1536) column on purpose rather than by
 * accident. The body/parse pair is shared with OllamaEmbed so there is ONE
 * place that orders vectors by index and one place that checks the width.
 */
final class OpenAiEmbed implements EmbedDriver
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

        return OllamaEmbed::parseResponse((int) $code, $decoded, $ms, \count($texts), $expected);
    }

    /**
     * @param string[] $texts
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     * @throws EmbedFailed
     */
    public static function buildBody(AiProfile $profile, array $texts, array $opts = []): array
    {
        $body = OllamaEmbed::buildBody($profile, $texts, $opts);

        // Ask the provider for the declared width when it supports doing so.
        // Ollama ignores an unknown key, but sending it there would be noise,
        // so only this driver adds it.
        $dims = isset($opts['dimensions']) ? (int) $opts['dimensions'] : $profile->embedDimensions();
        if ($dims > 0 && !\array_key_exists('dimensions', $body)) {
            $body['dimensions'] = $dims;
        }

        return $body;
    }
}
