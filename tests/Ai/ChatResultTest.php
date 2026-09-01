<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\Chat\ChatResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../src/Ai/AiConfig.php';
require_once __DIR__ . '/../../src/Ai/AiUsageLogger.php';
require_once __DIR__ . '/../../src/Ai/AiGateway.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatResult.php';

final class ChatResultTest extends TestCase
{
    public function testNotJsonVersusEmptyObject(): void
    {
        $prose = new ChatResult(200, 'Sure! Here is what I think.');
        self::assertNull($prose->decodeJson());
        self::assertFalse($prose->jsonValid());

        $empty = new ChatResult(200, "```json\n{}\n```");
        self::assertSame([], $empty->decodeJson());
        self::assertTrue($empty->jsonValid());

        $obj = new ChatResult(200, '{"category":"invoice","urgent":false}');
        self::assertSame(['category' => 'invoice', 'urgent' => false], $obj->decodeJson());
        self::assertTrue($obj->jsonValid());

        $blank = new ChatResult(200, '   ');
        self::assertNull($blank->decodeJson());
        self::assertFalse($blank->jsonValid());

        $none = new ChatResult(500, null);
        self::assertFalse($none->jsonValid());
        self::assertFalse($none->ok());
    }

    public function testUsageNormalisedFromEitherShape(): void
    {
        $openai = new ChatResult(200, 'x', ['prompt_tokens' => 12, 'completion_tokens' => 3]);
        self::assertSame(['input_tokens' => 12, 'output_tokens' => 3], $openai->usage());
        $anthropic = new ChatResult(200, 'x', ['input_tokens' => 7, 'output_tokens' => 1]);
        self::assertSame(['input_tokens' => 7, 'output_tokens' => 1], $anthropic->usage());
        self::assertSame(['input_tokens' => 0, 'output_tokens' => 0], (new ChatResult(200, 'x'))->usage());
        self::assertEqualsWithDelta(1_000_000 * 0.15 / 1_000_000 + 0, (new ChatResult(200, 'x', ['prompt_tokens' => 1_000_000]))->costUsd(['input_per_m' => 0.15, 'output_per_m' => 0.6]), 1e-9);
    }

    public function testTransportErrorIsNotOk(): void
    {
        $r = new ChatResult(0, null, [], 5, null, 'timeout');
        self::assertFalse($r->ok());
        self::assertSame('timeout', $r->transportError());
        self::assertSame(5, $r->latencyMs());
        self::assertTrue((new ChatResult(200, 'x'))->ok());
    }
}
