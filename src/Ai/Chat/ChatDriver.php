<?php

namespace ApiGoat\Ai\Chat;

use ApiGoat\Ai\AiProfile;

/**
 * One chat completion against whatever AiProfile says.
 *
 * Implementations: OpenAiChat (OpenAI and Ollama — both speak
 * /chat/completions). TODO(phase 1.5): AnthropicChat — /messages,
 * x-api-key + anthropic-version, system hoisted to top level, max_tokens
 * required, text at content[0].text; read the claude-api skill first.
 */
interface ChatDriver
{
    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $opts max_tokens, temperature, json_schema
     *   (array), json_schema_name, format (bool: Ollama-native `format`
     *   instead of `response_format`), timeout
     */
    public function complete(AiProfile $profile, array $messages, array $opts = []): ChatResult;
}
