<?php

namespace ApiGoat\Ai\Chat;

use ApiGoat\Ai\AiGateway;
use ApiGoat\Ai\AiProfile;

/**
 * Anthropic Messages API driver: system hoisted to the top level, max_tokens
 * always sent, json_schema delivered as a forced tool (answer returned as
 * JSON text), OpenAI-shaped image parts converted to Anthropic blocks.
 */
final class AnthropicChat implements ChatDriver
{
    public const PATH = '/messages';
    public const VERSION = '2023-06-01';
    public const DEFAULT_MAX_TOKENS = 1024;
    private const DROP_EXTRA = ['max_completion_tokens', 'reasoning_effort', 'keep_alive', 'think'];

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
            if ($role === 'system') { $system[] = is_string($m['content']) ? $m['content'] : ''; continue; }
            $turns[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => self::content($m['content'] ?? '')];
        }
        $model = isset($opts['model']) && is_string($opts['model']) && $opts['model'] !== '' ? $opts['model'] : $profile->model();
        $body = ['model' => $model, 'max_tokens' => isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : self::DEFAULT_MAX_TOKENS, 'messages' => $turns];
        if ($system !== []) $body['system'] = implode("\n\n", $system);
        if (isset($opts['temperature'])) $body['temperature'] = (float) $opts['temperature'];
        if (!empty($opts['json_schema']) && is_array($opts['json_schema'])) {
            $name = (string) ($opts['json_schema_name'] ?? 'response');
            $body['tools'] = [['name' => $name, 'description' => 'Return the answer as the tool input.', 'input_schema' => $opts['json_schema']]];
            $body['tool_choice'] = ['type' => 'tool', 'name' => $name];
        }
        foreach ((array) ($opts['extra'] ?? []) as $k => $v) {
            if (in_array($k, self::DROP_EXTRA, true) || array_key_exists($k, $body)) continue;
            $body[$k] = $v;
        }
        return $body;
    }

    /** string → string; OpenAI-shaped parts → Anthropic blocks. */
    private static function content($c)
    {
        if (!is_array($c)) return (string) $c;
        $out = [];
        foreach ($c as $part) {
            $type = $part['type'] ?? '';
            if ($type === 'text') { $out[] = ['type' => 'text', 'text' => (string) ($part['text'] ?? '')]; continue; }
            if ($type === 'image_url' && preg_match('#^data:([\w/.+-]+);base64,(.+)$#s', (string) ($part['image_url']['url'] ?? ''), $m)) {
                $out[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $m[1], 'data' => $m[2]]];
                continue;
            }
            if ($type === 'image') { $out[] = $part; }   // already native
        }
        return $out;
    }

    public static function parseResponse(int $status, $decoded, int $latencyMs): ChatResult
    {
        $text = null;
        if (is_array($decoded) && is_array($decoded['content'] ?? null)) {
            $parts = []; $tool = null;
            foreach ($decoded['content'] as $block) {
                if (($block['type'] ?? '') === 'text') $parts[] = (string) ($block['text'] ?? '');
                elseif (($block['type'] ?? '') === 'tool_use' && $tool === null) $tool = $block['input'] ?? null;
            }
            $text = $tool !== null ? json_encode($tool, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($parts !== [] ? implode('', $parts) : null);
        }
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $err = $status === 0 ? 'no HTTP response (transport error or timeout)' : '';
        return new ChatResult($status, $text, $usage, $latencyMs, $decoded, $err);
    }
}
