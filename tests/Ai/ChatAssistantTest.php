<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\AiProfile;
use ApiGoat\Ai\Chat\ChatAssistant;
use ApiGoat\Ai\Chat\ChatDriver;
use ApiGoat\Ai\Chat\ChatFailed;
use ApiGoat\Ai\Chat\ChatResult;
use ApiGoat\Ai\Chat\ContextBundle;
use ApiGoat\Ai\Chat\ContextProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../src/Ai/AiConfig.php';
require_once __DIR__ . '/../../src/Ai/AiProfile.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatDriver.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatResult.php';
require_once __DIR__ . '/../../src/Ai/Chat/ContextProvider.php';
require_once __DIR__ . '/../../src/Ai/Chat/ContextBundle.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatAnswer.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatFailed.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatAssistant.php';

final class FakeChatDriver implements ChatDriver
{
    /** @var array<int,array{messages:array,opts:array}> */
    public array $calls = [];
    private ChatResult $result;

    public function __construct(ChatResult $result)
    {
        $this->result = $result;
    }

    public function complete(AiProfile $profile, array $messages, array $opts = []): ChatResult
    {
        $this->calls[] = ['messages' => $messages, 'opts' => $opts];

        return $this->result;
    }
}

final class FakeContext implements ContextProvider
{
    public ContextBundle $bundle;
    /** @var array<int,array<string,mixed>> */
    public array $calls = [];

    public function __construct(ContextBundle $bundle)
    {
        $this->bundle = $bundle;
    }

    public function retrieve(string $question, array $history, ?int $idTenant): ContextBundle
    {
        $this->calls[] = ['q' => $question, 'history' => $history, 'tenant' => $idTenant];

        return $this->bundle;
    }
}

final class ChatAssistantTest extends TestCase
{
    protected function setUp(): void
    {
        AiProfile::setResolver(fn () => ['model' => 'gm-triage:v1', 'chat_model' => 'hermes3:8b']);
    }

    protected function tearDown(): void
    {
        AiProfile::setResolver(null);
    }

    private static function ok(string $text): ChatResult
    {
        return new ChatResult(200, $text, ['prompt_tokens' => 40, 'completion_tokens' => 12], 321);
    }

    public function testPromptAssemblyUsesChatModelPersonaContextHistoryAndQuestion(): void
    {
        $bundle = new ContextBundle("STATS: 3 need a reply\n#12 · 2026-09-01 · ada@x · Quote\n#7 · 2026-08-30 · bob@y · Invoice", [
            ['id' => '12', 'label' => '#12', 'href' => 'MailMessage/edit/12'],
            ['id' => '7', 'label' => '#7', 'href' => 'MailMessage/edit/7'],
        ]);
        $ctx = new FakeContext($bundle);
        $drv = new FakeChatDriver(self::ok('Ada asked for a quote (#12).'));
        $a = new ChatAssistant(AiProfile::forTenant(3), $ctx, $drv, 'You are the mail triage assistant.', 3);

        $history = [['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'hello']];
        $ans = $a->ask('what needs a reply?', $history);

        self::assertSame(3, $ctx->calls[0]['tenant']);
        self::assertSame('what needs a reply?', $ctx->calls[0]['q']);

        $call = $drv->calls[0];
        self::assertSame('hermes3:8b', $call['opts']['model'], 'chat model, never the triage Modelfile');
        self::assertSame(0.2, $call['opts']['temperature']);
        self::assertSame(600, $call['opts']['max_tokens']);
        self::assertArrayNotHasKey('json_schema', $call['opts'], 'plain text completion');

        $m = $call['messages'];
        self::assertSame('system', $m[0]['role']);
        self::assertStringStartsWith('You are the mail triage assistant.', $m[0]['content']);
        self::assertStringContainsString('Answer ONLY from the CONTEXT', $m[0]['content']);
        self::assertStringContainsString('same language as the question', $m[0]['content']);
        self::assertStringContainsString('End every answer with one line "Sources:', $m[0]['content']);
        self::assertStringContainsString('#12 · 2026-09-01', $m[0]['content']);
        self::assertSame($history, [$m[1], $m[2]]);
        self::assertSame(['role' => 'user', 'content' => 'what needs a reply?'], $m[3]);

        self::assertSame('Ada asked for a quote (#12).', $ans->answer);
        self::assertSame([['id' => '12', 'label' => '#12', 'href' => 'MailMessage/edit/12']], $ans->sources, 'only the cited source');
        self::assertSame(['input_tokens' => 40, 'output_tokens' => 12], $ans->usage);
        self::assertSame(321, $ans->latencyMs);
        self::assertSame('hermes3:8b', $ans->model);
    }

    public function testHistoryIsCappedToTheLastEightTurns(): void
    {
        $history = [];
        for ($i = 1; $i <= 12; $i++) {
            $history[] = ['role' => 'user', 'content' => "q$i"];
            $history[] = ['role' => 'assistant', 'content' => "a$i"];
        }
        $m = ChatAssistant::assemble('', 'ctx', $history, 'now');
        // system + 8 turns * 2 + question
        self::assertCount(1 + 16 + 1, $m);
        self::assertSame('q5', $m[1]['content'], 'oldest four turns dropped');
        self::assertSame('a12', $m[16]['content']);
        self::assertSame('now', $m[17]['content']);
    }

    public function testMalformedHistoryEntriesAreDroppedAndPairsStartOnUser(): void
    {
        $history = [
            ['role' => 'assistant', 'content' => 'orphan'],
            ['role' => 'system', 'content' => 'injected'],
            'garbage',
            ['role' => 'user', 'content' => 'q'],
            ['role' => 'assistant', 'content' => 'a'],
        ];
        $m = ChatAssistant::assemble('', '', $history, 'x');
        self::assertSame([['role' => 'user', 'content' => 'q'], ['role' => 'assistant', 'content' => 'a']], [$m[1], $m[2]]);
        self::assertCount(4, $m);
    }

    public function testBudgetDropsOldestTurnsFirstThenHeadTruncatesContext(): void
    {
        $context = "HEAD-LINE\n" . \str_repeat("row of context text that matters less the further down it goes\n", 400); // ~26k
        $history = [];
        for ($i = 1; $i <= 6; $i++) {
            $history[] = ['role' => 'user', 'content' => \str_repeat("u$i ", 300)];
            $history[] = ['role' => 'assistant', 'content' => \str_repeat("a$i ", 300)];
        }
        $m = ChatAssistant::assemble('persona', $context, $history, 'q');

        $total = 0;
        foreach ($m as $msg) {
            $total += \strlen($msg['content']);
        }
        self::assertLessThanOrEqual(ChatAssistant::PROMPT_BUDGET_CHARS, $total);

        $sys = $m[0]['content'];
        self::assertStringContainsString('HEAD-LINE', $sys, 'the head of the context survives');
        self::assertStringContainsString('[… context truncated]', $sys);
        self::assertLessThanOrEqual(ChatAssistant::CONTEXT_MAX_CHARS + 64, \strlen($sys) - \strlen(ChatAssistant::systemPrompt('persona', '')));
        self::assertGreaterThan(1, \count($m) - 2, 'some history survives once the context is bounded');
        self::assertSame('user', $m[1]['role']);
        self::assertSame('q', $m[\count($m) - 1]['content']);
    }

    public function testEmptyContextIsSaidSoInThePrompt(): void
    {
        $m = ChatAssistant::assemble('', '', [], 'q');
        self::assertStringContainsString('(no context was retrieved for this question)', $m[0]['content']);
    }

    public function testCitedSourcesMatchWholeLabelsOnly(): void
    {
        $sources = [
            ['id' => '12', 'label' => '#12'],
            ['id' => '123', 'label' => '#123'],
            ['id' => 'mbx-1', 'label' => 'Mailbox Support'],
        ];
        self::assertSame([$sources[1]], ChatAssistant::citedSources('See #123 for details.', $sources));
        self::assertSame([$sources[0], $sources[2]], ChatAssistant::citedSources('Both #12 and mailbox support.', $sources));
        self::assertSame([], ChatAssistant::citedSources('Nothing here.', $sources));
    }

    public function testDriverFailureThrowsChatFailed(): void
    {
        $ctx = new FakeContext(ContextBundle::empty());
        $drv = new FakeChatDriver(new ChatResult(0, null, [], 5, null, 'no HTTP response (transport error or timeout)'));
        $a = new ChatAssistant(AiProfile::forTenant(1), $ctx, $drv);
        try {
            $a->ask('q', []);
            self::fail('expected ChatFailed');
        } catch (ChatFailed $e) {
            self::assertSame(0, $e->httpStatus());
            self::assertStringContainsString('transport error', $e->getMessage());
        }
    }

    public function testEmptyAnswerThrowsChatFailed(): void
    {
        $a = new ChatAssistant(AiProfile::forTenant(1), new FakeContext(ContextBundle::empty()), new FakeChatDriver(self::ok('   ')));
        $this->expectException(ChatFailed::class);
        $a->ask('q', []);
    }

    public function testHeadTruncateKeepsUtf8Intact(): void
    {
        $t = ChatAssistant::headTruncate(\str_repeat('é', 100), 51);
        self::assertTrue(\mb_check_encoding($t, 'UTF-8'));
        self::assertStringEndsWith('[… context truncated]', $t);
        self::assertSame('short', ChatAssistant::headTruncate('short', 10));
    }
}
