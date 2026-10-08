<?php

namespace ApiGoat\Ai\Chat;

use ApiGoat\Ai\AiProfile;

/**
 * One chat completion against whatever AiProfile says.
 *
 * Implementations: OpenAiChat (OpenAI — /chat/completions), OllamaChat
 * (native), AnthropicChat (/messages: x-api-key + anthropic-version, system
 * hoisted, max_tokens required, forced-tool JSON).
 */
interface ChatDriver
{
    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $opts model (override of $profile->model()), max_tokens, temperature, json_schema
     *   (array), json_schema_name, format (bool: Ollama-native `format`
     *   instead of `response_format`), timeout
     */
    public function complete(AiProfile $profile, array $messages, array $opts = []): ChatResult;
}
