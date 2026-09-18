<?php

namespace ApiGoat\Ai\Chat;

use ApiGoat\Ai\AiGateway;
use ApiGoat\Ai\AiProfile;

/**
 * Ollama's NATIVE /api/chat, as opposed to its OpenAI-compatible /v1 shim.
 *
 * The shim is fine for most things, and {@see OpenAiChat} should stay the
 * default. This driver exists for one reason: reasoning models cannot be
 * told to stop reasoning through /v1.
 *
 * Measured on qwen3.5:9b, same prompt, same host, 2026-09-14:
 *
 *   /v1, no flag                     timed out (>240 s)
 *   /v1 chat_template_kwargs         timed out (>240 s)  — silently ignored
 *   /v1 think:false                  222,805 ms, 3,847 output tokens
 *   /api/chat think:false              1,003 ms,    14 output tokens
 *
 * The /v1 think:false row is the instructive one: the request returns, but
 * the model reasoned for 3,833 tokens that were then discarded. Two hundred
 * and twenty times the latency for the same two-key answer.
 *
 * The alternative — the one this replaces — was to hand-edit the chat
 * TEMPLATE inside a Modelfile so the sentinel a particular model happens to
 * understand is always appended. That works until the model is upgraded:
 * qwen3.5's template carries no such sentinel at all.
 *
 * Differences from the OpenAI shape, all handled here:
 *   - path /api/chat, and the base URL drops a trailing /v1
 *   - `think` is a top-level boolean
 *   - sampling lives under `options` (num_predict, not max_tokens)
 *   - structured output is `format: <schema>`, not `response_format`
 *   - the answer is message.content; usage is prompt_eval_count/eval_count
 */
final class OllamaChat implements ChatDriver
{
    public const PATH = '/api/chat';

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
        $gw['base_url'] = self::nativeBase((string) ($gw['base_url'] ?? ''));
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
     * The native API lives beside /v1, not under it: a profile configured as
     * http://host:11434/v1 must POST to http://host:11434/api/chat.
     */
    public static function nativeBase(string $baseUrl): string
    {
        $b = \rtrim($baseUrl, '/');
        return (string) (\preg_replace('#/v1$#', '', $b) ?? $b);
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function buildBody(AiProfile $profile, array $messages, array $opts = []): array
    {
        $body = [
            'model'    => isset($opts['model']) && \is_string($opts['model']) && $opts['model'] !== ''
                ? $opts['model'] : $profile->model(),
            'messages' => \array_values($messages),
            'stream'   => false,
            // The whole point. Opt OUT explicitly rather than relying on a
            // model's template default, which varies by model and version.
            'think'    => (bool) ($opts['think'] ?? false),
        ];

        $options = [];
        if (isset($opts['temperature'])) {
            $options['temperature'] = (float) $opts['temperature'];
        }
        if (isset($opts['max_tokens'])) {
            $options['num_predict'] = (int) $opts['max_tokens'];
        }
        // Everything else Ollama's `options` block accepts — top_k, top_p,
        // presence_penalty, repeat_penalty, seed, num_ctx … Without this the
        // only sampling values in play are the model's BAKED ones, which come
        // from whatever base model the tag was built FROM and change silently
        // on a model upgrade (gm-triage:v3 inherits presence_penalty 1.5 from
        // qwen3.5:9b — harmless under constrained JSON decoding, strained
        // across several hundred words of prose). `extra` cannot reach here:
        // it merges at the TOP level and skips keys already present. The
        // caller wins on a collision.
        if (isset($opts['options']) && \is_array($opts['options'])) {
            $options = \array_merge($options, $opts['options']);
        }
        if ($options !== []) {
            $body['options'] = $options;
        }

        // Ollama's structured output: the bare JSON Schema, no envelope.
        if (isset($opts['json_schema']) && \is_array($opts['json_schema'])) {
            $body['format'] = $opts['json_schema'];
        }

        if (isset($opts['extra']) && \is_array($opts['extra'])) {
            foreach ($opts['extra'] as $k => $v) {
                if (!\array_key_exists($k, $body)) {
                    $body[$k] = $v;
                }
            }
        }

        // The pin. Ollama's keep_alive is per REQUEST and resets the model's
        // expiry, so a chat request that omits it drops a model pinned with
        // -1 down to the daemon's 20-minute default — twenty idle minutes
        // later the next triage pays a cold load. Applied last and only when
        // nothing (an explicit `extra.keep_alive`) already set it.
        if (!\array_key_exists('keep_alive', $body)) {
            $keepAlive = $profile->keepAlive();
            if ($keepAlive !== null) {
                $body['keep_alive'] = $keepAlive;
            }
        }

        return $body;
    }

    /**
     * Normalise /api/chat's answer into the same ChatResult every other
     * driver returns, so callers cannot tell which endpoint was used.
     *
     * @param mixed $decoded
     */
    public static function parseResponse(int $status, $decoded, int $latencyMs): ChatResult
    {
        $text = null;
        $usage = [];
        $err = '';

        if (\is_array($decoded)) {
            $content = $decoded['message']['content'] ?? null;
            $text = \is_string($content) ? $content : null;
            // Shaped like OpenAI's usage block so ChatResult needs no branch.
            $usage = [
                'prompt_tokens'     => (int) ($decoded['prompt_eval_count'] ?? 0),
                'completion_tokens' => (int) ($decoded['eval_count'] ?? 0),
            ];
            if (isset($decoded['error'])) {
                $err = \is_string($decoded['error']) ? $decoded['error'] : 'ollama error';
            }
        }
        if ($status === 0) {
            $err = 'no HTTP response (transport error or timeout)';
        }

        return new ChatResult($status, $text, $usage, $latencyMs, $decoded, $err);
    }
}
