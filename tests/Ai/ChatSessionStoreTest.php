<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\Chat\ChatSessionStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/Chat/ChatAssistant.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatSessionStore.php';

final class ChatSessionStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAppendHistoryAndResetArePerModel(): void
    {
        $a = new ChatSessionStore('MailMessage');
        $b = new ChatSessionStore('Client');
        $a->append('q1', 'a1', [['id' => '1', 'label' => '#1']]);
        $b->append('other', 'thing');

        self::assertSame(
            [['role' => 'user', 'content' => 'q1'], ['role' => 'assistant', 'content' => 'a1']],
            $a->history()
        );
        self::assertSame([['id' => '1', 'label' => '#1']], $a->turns()[0]['sources']);
        self::assertCount(2, $b->history());

        $a->reset();
        self::assertSame([], $a->history());
        self::assertSame([], $a->turns());
        self::assertCount(2, $b->history(), 'reset is scoped to its model');
        self::assertArrayNotHasKey('MailMessage', $_SESSION[ChatSessionStore::SESSION_KEY]);
    }

    public function testCapKeepsTheMostRecentTurns(): void
    {
        $s = new ChatSessionStore('MailMessage');
        for ($i = 1; $i <= ChatSessionStore::MAX_TURNS + 3; $i++) {
            $s->append("q$i", "a$i");
        }
        $turns = $s->turns();
        self::assertCount(ChatSessionStore::MAX_TURNS, $turns);
        self::assertSame('q4', $turns[0]['q']);
        self::assertSame('a' . (ChatSessionStore::MAX_TURNS + 3), $turns[ChatSessionStore::MAX_TURNS - 1]['a']);
    }

    public function testInvalidModelNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChatSessionStore('../x');
    }
}
