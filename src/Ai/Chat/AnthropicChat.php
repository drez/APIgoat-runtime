<?php

namespace ApiGoat\Ai\Chat;

use ApiGoat\Ai\AiGateway;
use ApiGoat\Ai\AiProfile;

/**
 * Anthropic Messages API driver: system hoisted to the top level, max_tokens
 * always sent, OpenAI-shaped image parts converted to Anthropic blocks,
 * empty strings never sent (no empty text blocks / empty turns).
 *
 * - temperature is NEVER sent: claude-*-5-5 reject non-default sampling with a 400.
 * - json_schema is delivered as structured outputs,
 *   output_config.format = {type: json_schema, schema}; the answer is JSON text in
 *   content[].text. (Forced tool_choice 400s on Sonnet 5.5.) A tool_use block is
 *   parsed only as a fallback when there is no text.
 * - Per-model rules (modelRules(), merged into output_config without clobbering format):
 *     claude-sonnet-5-5 -> thinking {type: between_tools} (lowest setting; "disabled" 400s there)
 *     claude-haiku-5-5  -> nothing added (the bundled API reference documents no Haiku 5.5
 *                          thinking/effort rule, so none is guessed)
 *     other claude-*    -> nothing added
 * - stop_reason refusal, or max_tokens without any text, is reported as a failure
 *   (transportError) so callers fall back instead of treating null text as success.
 */
final class AnthropicChat implements ChatDriver
{
    public const PATH = '/messages';
    public const VERSION = '2023-06-01';
    public const DEFAULT_MAX_TOKENS = 1024;
    private const DROP_EXTRA = ['max_completion_tokens', 'reasoning_effort', 'keep_alive', 'think', 'temperature'];

    private $transport;
    public function __construct(?callable $transport = null) { $this->transport = $transport; }

    public function complete(AiProfile $profile, array $messages, array $opts = []): ChatResult
    {
        $body = self::buildBody($profile, $messages, $opts);
        $gw = $profile->gatewayOpts();
        if (isset($opts['timeout'])) $gw['timeout'] = (int) $opts['timeout'];
        $post = $this->transport ?? [AiGateway::class, 'post'];
        $t0 = microtime(true);
        [$code, $decoded] = $post(self::PATH, $body, $gw);
        return self::parseResponse((int) $code, $decoded, (int) round((microtime(true) - $t0) * 1000));
    }

    public static function buildBody(AiProfile $profile, array $messages, array $opts): array
    {
        $system = []; $turns = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            if ($role === 'system') {
                $sys = self::systemText($m['content'] ?? null);
                if ($sys !== '') $system[] = $sys;
                continue;
            }
            $role = $role === 'assistant' ? 'assistant' : 'user';
            $content = self::content($m['content'] ?? '');
            if ($content === '' || $content === []) continue;   // Anthropic 400s on empty text; drop the turn
            $last = count($turns) - 1;
            if ($last >= 0 && $turns[$last]['role'] === $role) {
                $turns[$last]['content'] = self::mergeContent($turns[$last]['content'], $content);
                continue;
            }
            $turns[] = ['role' => $role, 'content' => $content];
        }
        $model = isset($opts['model']) && is_string($opts['model']) && $opts['model'] !== '' ? $opts['model'] : $profile->model();
        $body = ['model' => $model, 'max_tokens' => isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : self::DEFAULT_MAX_TOKENS, 'messages' => $turns];
        if ($system !== []) $body['system'] = implode("\n\n", $system);
        // temperature deliberately not sent (see class docblock).
        if (!empty($opts['json_schema']) && is_array($opts['json_schema'])) {
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $opts['json_schema']]];
        }
        foreach (self::modelRules($model) as $k => $v) {
            // output_config is one dict: merge into it, never replace (keeps format)
            $body[$k] = is_array($v) && is_array($body[$k] ?? null) ? array_replace_recursive($body[$k], $v) : $v;
        }
        foreach ((array) ($opts['extra'] ?? []) as $k => $v) {
            if (in_array($k, self::DROP_EXTRA, true) || array_key_exists($k, $body)) continue;
            $body[$k] = $v;
        }
        return $body;
    }

    /** Extra body keys a given model requires/accepts (see class docblock). */
    public static function modelRules(string $model): array
    {
        if ($model === 'claude-sonnet-5-5') return ['thinking' => ['type' => 'between_tools']];
        return [];   // claude-haiku-5-5 and any other claude-*: nothing documented, send nothing
    }

    /** System content as text: strings as-is, text-only block arrays joined; anything else is skipped. */
    private static function systemText($c): string
    {
        if (is_string($c)) return $c;
        if (!is_array($c)) return '';
        $parts = [];
        foreach ($c as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) $parts[] = $part['text'];
        }
        return implode("\n\n", $parts);
    }

    /** Merge two same-role contents (the Messages API rejects consecutive same-role turns). */
    private static function mergeContent($a, $b)
    {
        if (is_string($a) && is_string($b)) return $a . "\n\n" . $b;
        $blocks = static fn ($c): array => is_array($c) ? $c : ($c === '' ? [] : [['type' => 'text', 'text' => (string) $c]]);
        return array_merge($blocks($a), $blocks($b));
    }

    /** string → string; OpenAI-shaped parts → Anthropic blocks. */
    private static function content($c)
    {
        if (!is_array($c)) return (string) $c;
        $out = [];
        foreach ($c as $part) {
            $type = $part['type'] ?? '';
            if ($type === 'text') {
                $t = (string) ($part['text'] ?? '');
                if ($t !== '') $out[] = ['type' => 'text', 'text' => $t];
                continue;
            }
            if ($type === 'image_url' && preg_match('#^data:([\w/.+-]+);base64,(.+)$#s', (string) ($part['image_url']['url'] ?? ''), $m)) {
                $out[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $m[1], 'data' => preg_replace('/\\s+/', '', $m[2])]];
                continue;
            }
            if ($type === 'image') { $out[] = $part; }   // already native
        }
        return $out;
    }

    public static function parseResponse(int $status, $decoded, int $latencyMs): ChatResult
    {
        $text = null; $stop = '';
        if (is_array($decoded) && is_array($decoded['content'] ?? null)) {
            $parts = []; $tool = null;
            foreach ($decoded['content'] as $block) {
                if (($block['type'] ?? '') === 'text') $parts[] = (string) ($block['text'] ?? '');
                elseif (($block['type'] ?? '') === 'tool_use' && $tool === null) $tool = $block['input'] ?? null;
            }
            $joined = implode('', $parts);
            if ($joined !== '') $text = $joined;
            elseif ($tool !== null) $text = json_encode($tool, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);   // fallback only
        }
        if (is_array($decoded)) $stop = (string) ($decoded['stop_reason'] ?? '');
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $err = $status === 0 ? 'no HTTP response (transport error or timeout)' : '';
        // ChatResult::ok() has no other failure channel than transportError, so a 2xx that
        // carries no usable answer is reported through it; callers then fall back (status kept).
        if ($err === '' && $status >= 200 && $status < 300 && ($stop === 'refusal' || ($stop === 'max_tokens' && ($text === null || $text === '')))) {
            $err = 'stop_reason: ' . $stop;
        }
        return new ChatResult($status, $text, $usage, $latencyMs, $decoded, $err);
    }
}
