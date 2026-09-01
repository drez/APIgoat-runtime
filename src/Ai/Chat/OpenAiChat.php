<?php

namespace ApiGoat\Ai\Chat;

use ApiGoat\Ai\AiGateway;
use ApiGoat\Ai\AiProfile;

/**
 * /chat/completions driver — OpenAI and Ollama's OpenAI-compatible /v1.
 *
 * Structured output: when $opts['json_schema'] is set the request carries
 * OpenAI's `response_format: {type: json_schema, json_schema: {name, schema,
 * strict: true}}`, which Ollama /v1 accepts too. If a given Ollama build
 * rejects it, pass $opts['format'] = true to send Ollama-native
 * `format: <schema>` instead (constrained decoding either way).
 */
final class OpenAiChat implements ChatDriver
{
    public const PATH = '/chat/completions';

    /** @var callable|null fn(string $path, array $body, array $opts): array{0:int,1:mixed} — test seam */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function complete(AiProfile $profile, array $messages, array $opts = []): ChatResult
    {
        $body = self::buildBody($profile, $messages, $opts);
        $gw = $profile->gatewayOpts();
        if (isset($opts['timeout'])) {
            $gw['timeout'] = (int) $opts['timeout'];
        }

        $t0 = \microtime(true);
        $post = $this->transport ?? [AiGateway::class, 'post'];
        [$code, $decoded] = $post(self::PATH, $body, $gw);
        $ms = (int) \round((\microtime(true) - $t0) * 1000);

        return self::parseResponse((int) $code, $decoded, $ms);
    }

    /**
     * The request body, shaped for /chat/completions.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function buildBody(AiProfile $profile, array $messages, array $opts = []): array
    {
        $body = [
            'model'    => $profile->model(),
            'messages' => \array_values($messages),
        ];
        if (isset($opts['max_tokens'])) {
            $body['max_tokens'] = (int) $opts['max_tokens'];
        }
        if (isset($opts['temperature'])) {
            $body['temperature'] = (float) $opts['temperature'];
        }
        if (isset($opts['json_schema']) && \is_array($opts['json_schema'])) {
            $schema = $opts['json_schema'];
            if (!empty($opts['format'])) {
                $body['format'] = $schema;
            } else {
                $body['response_format'] = [
                    'type'        => 'json_schema',
                    'json_schema' => [
                        'name'   => (string) ($opts['json_schema_name'] ?? 'response'),
                        'schema' => $schema,
                        'strict' => true,
                    ],
                ];
            }
        }

        return $body;
    }

    /**
     * Normalise a /chat/completions answer (or its absence) into a ChatResult.
     *
     * @param mixed $decoded
     */
    public static function parseResponse(int $status, $decoded, int $latencyMs): ChatResult
    {
        $text = null;
        $usage = [];
        $err = '';
        if (\is_array($decoded)) {
            $content = $decoded['choices'][0]['message']['content'] ?? null;
            $text = \is_string($content) ? $content : null;
            $usage = \is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        }
        if ($status === 0) {
            $err = 'no HTTP response (transport error or timeout)';
        }

        return new ChatResult($status, $text, $usage, $latencyMs, $decoded, $err);
    }
}
