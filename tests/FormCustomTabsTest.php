<?php

use PHPUnit\Framework\TestCase;

/**
 * FormHelper::addFormTab / renderFormCustomTabs — the wrapper-side API for
 * extra drawer tabs (a rendered email, a preview…) beside the generated ones.
 */
final class FormCustomTabsTest extends TestCase
{
    private function host(): object
    {
        return new class { use \ApiGoat\Utility\FormHelper; };
    }

    public function test_no_tabs_leaves_the_generated_first_tab_active(): void
    {
        $this->assertSame(['', '', '', '', true], $this->host()->renderFormCustomTabs());
    }

    public function test_a_first_tab_is_active_and_demotes_the_generated_one(): void
    {
        $h = $this->host();
        $h->addFormTab('Email', '<p>body</p>', ['first' => true]);
        [$navFirst, $navLast, $panesFirst, $panesLast, $generatedActive] = $h->renderFormCustomTabs();

        $this->assertFalse($generatedActive);
        $this->assertSame('', $navLast);
        $this->assertSame('', $panesLast);
        $this->assertStringContainsString('class="tab-btn is-active"', $navFirst);
        $this->assertStringContainsString('data-tab="tab_x_email"', $navFirst);
        $this->assertStringContainsString('aria-selected="true"', $navFirst);
        $this->assertStringContainsString('<div id="tab_x_email" class="tab-pane is-active" role="tabpanel" data-tab="tab_x_email"><p>body</p></div>', $panesFirst);
    }

    public function test_a_trailing_tab_is_appended_hidden_and_keeps_the_generated_first_tab(): void
    {
        $h = $this->host();
        $h->addFormTab('Preview', '<em>x</em>');
        [$navFirst, $navLast, $panesFirst, $panesLast, $generatedActive] = $h->renderFormCustomTabs();

        $this->assertTrue($generatedActive);
        $this->assertSame('', $navFirst . $panesFirst);
        $this->assertStringContainsString('class="tab-btn" role="tab" data-tab="tab_x_preview" aria-selected="false"', $navLast);
        $this->assertStringContainsString('class="tab-pane" role="tabpanel" data-tab="tab_x_preview" hidden>', $panesLast);
    }

    public function test_only_the_first_registered_first_tab_is_active(): void
    {
        $h = $this->host();
        $h->addFormTab('A', 'a', ['first' => true]);
        $h->addFormTab('B', 'b', ['first' => true]);
        [$navFirst] = $h->renderFormCustomTabs();
        $this->assertSame(1, substr_count($navFirst, 'is-active'));
        $this->assertStringContainsString('data-tab="tab_x_a" aria-selected="true"', $navFirst);
    }

    public function test_label_is_escaped_and_key_is_slugged(): void
    {
        $h = $this->host();
        $h->addFormTab('<b>Raw</b> & co', 'x', ['key' => 'My Key!']);
        [, $navLast] = $h->renderFormCustomTabs();
        $this->assertStringContainsString('&lt;b&gt;Raw&lt;/b&gt; &amp; co</button>', $navLast);
        $this->assertStringContainsString('data-tab="tab_x_my_key"', $navLast);
    }

    public function test_an_unusable_key_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->host()->addFormTab('!!!', 'x');
    }
}
