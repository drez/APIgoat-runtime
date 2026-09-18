<?php

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\AiUsageLogger;
use PHPUnit\Framework\TestCase;

/**
 * The two pure classifiers. Both were silently wrong for Ollama's NATIVE
 * endpoints, which is how apigmail logged 1,662 triage calls as `other` with
 * 0/0 tokens between 2026-09-14 and 2026-09-18.
 */
final class AiUsageLoggerTest extends TestCase
{
    /** @dataProvider paths */
    public function testOperationFor(string $path, string $expected): void
    {
        $this->assertSame($expected, AiUsageLogger::operationFor($path));
    }

    public static function paths(): array
    {
        return [
            'openai chat'      => ['/v1/chat/completions', 'chat'],
            'ollama chat'      => ['/api/chat', 'chat'],
            'ollama generate'  => ['/api/generate', 'chat'],
            'openai embed'     => ['/v1/embeddings', 'embed'],
            'ollama embed'     => ['/api/embed', 'embed'],
            'images'           => ['/v1/images/generations', 'image_generate'],
            'tts'              => ['/v1/audio/speech', 'tts'],
            'stt'              => ['/v1/audio/transcriptions', 'stt'],
            'ping'             => ['/v1/models', 'ping'],
            'unknown'          => ['/v1/moderations', 'other'],
        ];
    }

    public function testOpenAiUsageBlockStillWins(): void
    {
        $this->assertSame([11, 22], AiUsageLogger::tokensOf([
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 22],
        ]));
    }

    public function testResponsesApiKeys(): void
    {
        $this->assertSame([5, 6], AiUsageLogger::tokensOf([
            'usage' => ['input_tokens' => 5, 'output_tokens' => 6],
        ]));
    }

    /** The regression: native Ollama reports at the TOP level, with no usage block. */
    public function testOllamaNativeTopLevelCounts(): void
    {
        $this->assertSame([1472, 144], AiUsageLogger::tokensOf([
            'model'             => 'gm-triage:v3',
            'prompt_eval_count' => 1472,
            'eval_count'        => 144,
        ]));
    }

    public function testNativeEmbedHasNoOutputTokens(): void
    {
        $this->assertSame([843, 0], AiUsageLogger::tokensOf(['prompt_eval_count' => 843]));
    }

    public function testGarbageIsZeroNotAnError(): void
    {
        $this->assertSame([0, 0], AiUsageLogger::tokensOf(null));
        $this->assertSame([0, 0], AiUsageLogger::tokensOf('nope'));
        $this->assertSame([0, 0], AiUsageLogger::tokensOf([]));
    }
}
