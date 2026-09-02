<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\Chat\ChatPanel;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/Chat/ChatPanel.php';

final class ChatPanelTest extends TestCase
{
    public function testHtmlCarriesEndpointLabelsModelAndNoInlineHandlers(): void
    {
        $html = ChatPanel::html([
            'endpoint' => 'https://gc.local/apigmail/.admin/MailMessage/chat',
            'label'    => 'Ask about my email',
            'model'    => 'hermes3:8b',
            'turns'    => [['q' => 'hi <b>', 'a' => 'hello', 'sources' => [['id' => '5', 'label' => '#5', 'href' => 'MailMessage/edit/5']]]],
        ]);

        self::assertStringContainsString('MailMessage/chat', $html);
        self::assertStringContainsString('Ask about my email', $html);
        self::assertStringContainsString('hermes3:8b', $html);
        self::assertStringContainsString('local model', $html);
        self::assertStringContainsString('New chat', $html);
        self::assertStringContainsString('data-gc-ai-chat', $html);
        self::assertStringContainsString('<div class="gc-aichat-drawer" role="dialog"', $html);
        self::assertMatchesRegularExpression('/gc-aichat-drawer"[^>]*\bhidden\b/', $html, 'hidden by default');
        self::assertStringContainsString('hi &lt;b&gt;', $html, 'pre-rendered turns are escaped');
        self::assertStringContainsString('href="MailMessage/edit/5"', $html);

        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, 'no inline event handlers');
        self::assertStringNotContainsString('alert(', \str_replace('alertb(', '', $html), 'no native alert');
        self::assertStringNotContainsString('jQuery', $html);
        self::assertStringNotContainsString('$(', $html);
        self::assertStringContainsString('var(--colorPrimary', $html, 'theme custom properties');
        self::assertStringContainsString('.gc-aichat [hidden]{display:none!important}', $html, 'the flex drawer must still honour hidden');
    }

    public function testFooterHiddenWithoutModelAndEndpointRequired(): void
    {
        $html = ChatPanel::html(['endpoint' => '/x/chat']);
        self::assertStringNotContainsString('gc-aichat-foot"', $html);
        self::assertStringContainsString('Ask AI', $html);

        $this->expectException(\InvalidArgumentException::class);
        ChatPanel::html([]);
    }

    public function testJsIsIdempotentAndSharedWithInlineScript(): void
    {
        $js = ChatPanel::js();
        self::assertStringContainsString('if (!window.GcAiChatWidget)', $js);
        self::assertStringContainsString('data-gc-ai-bound', $js);
        self::assertStringContainsString('X-Csrf-Token', $js);
        $html = ChatPanel::html(['endpoint' => '/x/chat']);
        self::assertStringContainsString('<script>' . $js . '</script>', $html);
    }

    public function testSourcesHtmlEscapes(): void
    {
        $h = ChatPanel::sourcesHtml([['label' => '<#1>', 'href' => 'a?b=1&c=2'], ['label' => 'plain']]);
        self::assertStringContainsString('&lt;#1&gt;', $h);
        self::assertStringContainsString('href="a?b=1&amp;c=2"', $h);
        self::assertStringContainsString('<span class="gc-aichat-src">plain</span>', $h);
        self::assertSame('', ChatPanel::sourcesHtml([]));
    }
}
