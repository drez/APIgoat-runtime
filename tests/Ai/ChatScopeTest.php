<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\AiProfile;
use ApiGoat\Ai\Chat\ChatAssistant;
use ApiGoat\Ai\Chat\ChatDriver;
use ApiGoat\Ai\Chat\ChatPanel;
use ApiGoat\Ai\Chat\ChatResult;
use ApiGoat\Ai\Chat\ChatScopeInvalid;
use ApiGoat\Ai\Chat\ChatSessionStore;
use ApiGoat\Ai\Chat\ContextBundle;
use ApiGoat\Ai\Chat\ContextProvider;
use ApiGoat\Ai\Chat\ScopedContextProvider;
use ApiGoat\Tests\Ai\Support\ManifestFixture;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../src/Ai/AiConfig.php';
require_once __DIR__ . '/../../src/Ai/AiProfile.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatDriver.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatResult.php';
require_once __DIR__ . '/../../src/Ai/Chat/ContextProvider.php';
require_once __DIR__ . '/../../src/Ai/Chat/ScopedContextProvider.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatScopeInvalid.php';
require_once __DIR__ . '/../../src/Ai/Chat/ContextBundle.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatAnswer.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatFailed.php';
require_once __DIR__ . '/../../src/Ai/AiUsageLogger.php';
require_once __DIR__ . '/../../src/Ai/AiGateway.php';
require_once __DIR__ . '/../../src/Ai/Chat/OpenAiChat.php';
require_once __DIR__ . '/../../src/Ai/Chat/OllamaChat.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatAssistant.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatSessionStore.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatPanel.php';
require_once __DIR__ . '/support/ManifestFixture.php';

/** Records every retrieve() call, scoped. */
final class ScopeFakeScopedContext implements ScopedContextProvider
{
    /** @var array<int,array<string,mixed>> */
    public array $calls = [];

    public function retrieve(string $question, array $history, ?int $idTenant, array $options = []): ContextBundle
    {
        $this->calls[] = ['args' => \func_num_args(), 'options' => $options, 'tenant' => $idTenant];
        if (isset($options['scope']) && $options['scope'] === 'foreign') {
            throw new ChatScopeInvalid('Unknown scope.');
        }

        return new ContextBundle('CTX ' . ($options['scope'] ?? 'all'));
    }

    public function scopes(?int $idTenant): array
    {
        return [['value' => '', 'label' => 'All'], ['value' => '7', 'label' => 'Sales']];
    }
}

/** A provider written before scopes existed: three parameters, no scopes(). */
final class ScopeFakeLegacyContext implements ContextProvider
{
    public int $calls = 0;

    public function retrieve(string $question, array $history, ?int $idTenant): ContextBundle
    {
        $this->calls++;

        return new ContextBundle('CTX legacy');
    }
}

final class ScopeFakeDriver implements ChatDriver
{
    /** @var array<int,array> */
    public array $messages = [];

    public function complete(AiProfile $profile, array $messages, array $opts = []): ChatResult
    {
        $this->messages[] = $messages;

        return new ChatResult(200, 'ok', [], 1);
    }
}

/**
 * The runtime half of the with_ai.chat scope option: the assistant forwards
 * `scope` to a ScopedContextProvider only, refuses it for a legacy provider
 * (never a silent widen), the session store keeps one history per scope and
 * drops it on a switch, and the panel renders the switcher only when there
 * is something to switch between.
 */
final class ChatScopeTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        AiProfile::setResolver(fn () => ['model' => 'gm-triage:v1', 'chat_model' => 'hermes3:8b']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        AiProfile::setResolver(null);
        ManifestFixture::clear();
    }

    public function testScopeReachesAScopedProviderAndUnscopedCallsCarryNoScope(): void
    {
        $ctx = new ScopeFakeScopedContext();
        $drv = new ScopeFakeDriver();
        $a = new ChatAssistant(AiProfile::forTenant(3), $ctx, $drv, '', 3);

        $a->ask('q', [], ['scope' => '7']);
        $a->ask('q', []);
        $a->ask('q', [], ['scope' => '']);

        self::assertSame(['scope' => '7'], $ctx->calls[0]['options']);
        self::assertSame([], $ctx->calls[1]['options'], 'no option = everything');
        self::assertSame([], $ctx->calls[2]['options'], '"" = everything');
        self::assertSame(3, $ctx->calls[0]['tenant']);
        self::assertStringContainsString('CTX 7', $drv->messages[0][0]['content']);
    }

    public function testAProviderRefusalPropagatesAsChatScopeInvalid(): void
    {
        $a = new ChatAssistant(AiProfile::forTenant(3), new ScopeFakeScopedContext(), new ScopeFakeDriver(), '', 3);
        $this->expectException(ChatScopeInvalid::class);
        $a->ask('q', [], ['scope' => 'foreign']);
    }

    public function testALegacyProviderKeepsWorkingAndRefusesAScope(): void
    {
        $ctx = new ScopeFakeLegacyContext();
        $a = new ChatAssistant(AiProfile::forTenant(3), $ctx, new ScopeFakeDriver(), '', 3);
        $a->ask('q', []);
        $a->ask('q', [], ['scope' => '']);
        self::assertSame(2, $ctx->calls);

        try {
            $a->ask('q', [], ['scope' => '7']);
            self::fail('a scope must never be dropped silently');
        } catch (ChatScopeInvalid $e) {
            self::assertSame(2, $ctx->calls, 'refused before any retrieval');
        }
    }

    public function testSwitchingScopeDropsTheHistoryAndNewChatKeepsTheScope(): void
    {
        $s = new ChatSessionStore('MailMessage');
        self::assertSame('', $s->scope());
        self::assertFalse($s->useScope(''), 'same scope: nothing to drop');

        $s->append('q all', 'a all');
        self::assertTrue($s->useScope('7'), 'history of "all" dropped on the switch');
        self::assertSame([], $s->history());
        self::assertSame('7', $s->scope());

        $s->append('q 7', 'a 7');
        self::assertFalse($s->useScope('7'));
        self::assertCount(2, $s->history(), 'same scope keeps its history');

        $s->reset();
        self::assertSame('7', $s->scope(), '"New chat" stays in the chosen scope');

        $s->append('q 7b', 'a 7b');
        self::assertTrue($s->useScope(''));
        self::assertSame([], $s->turns());
        self::assertSame('', $s->scope());
        self::assertArrayNotHasKey('MailMessage', $_SESSION[ChatSessionStore::SCOPE_KEY]);

        $other = new ChatSessionStore('Client');
        $other->append('x', 'y');
        $s->useScope('9');
        self::assertCount(2, $other->history(), 'scope is per model');
    }

    public function testPanelRendersTheSwitcherOnlyWithTwoChoicesAndEscapes(): void
    {
        $none = ChatPanel::html(['endpoint' => '/x/chat']);
        self::assertStringNotContainsString('data-gc-ai-scope="1"', $none);
        $one = ChatPanel::html(['endpoint' => '/x/chat', 'scopes' => [['value' => '', 'label' => 'All']]]);
        self::assertStringNotContainsString('data-gc-ai-scope="1"', $one);

        $html = ChatPanel::html([
            'endpoint' => '/x/chat',
            'scopes'   => [['value' => '', 'label' => 'All'], ['value' => '7', 'label' => 'Sales <EU>'], ['value' => '0', 'label' => 'Ungrouped'], 'junk'],
            'scope'    => '7',
            'labels'   => ['scope' => 'Group'],
        ]);
        self::assertStringContainsString('data-gc-ai-scope="1"', $html);
        self::assertStringContainsString('<span class="gc-aichat-scope-l">Group</span>', $html);
        self::assertStringContainsString('<option value="7" selected>Sales &lt;EU&gt;</option>', $html);
        self::assertStringContainsString('<option value="">All</option>', $html);
        self::assertStringContainsString('<option value="0">Ungrouped</option>', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, 'no inline event handlers');

        $stale = ChatPanel::scopeHtml([['value' => '', 'label' => 'All'], ['value' => '7', 'label' => 'S']], '99');
        self::assertStringContainsString('<option value="" selected>All</option>', $stale, 'unknown selection falls back to the first choice');
    }

    public function testWidgetSendsTheScopeAndResetsOnChange(): void
    {
        $js = ChatPanel::js();
        self::assertStringContainsString("q('[data-gc-ai-scope]')", $js);
        self::assertStringContainsString('post(withScope({ message: text }))', $js);
        self::assertStringContainsString("scopeSel.addEventListener('change'", $js);
        self::assertStringContainsString('post(withScope({ reset: true }))', $js);
    }
}
