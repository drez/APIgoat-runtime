<?php

use PHPUnit\Framework\TestCase;

// assocToNumDef()'s default $valeur argument references it.
if (!defined('_MESS_SELECTION')) { define('_MESS_SELECTION', 'Selection'); }
require_once __DIR__ . '/../../src/Utility/Legacy/html_helper.php';

/**
 * C4 — assocToNum()'s $addDefault used to be accepted and silently ignored
 * (the body was commented out in all four variants), so a nullable FK never got
 * a blank option and could not be cleared in any consumer that renders the raw
 * option list.
 */
final class AssocToNumDefaultTest extends TestCase
{
    /** @return list<array<string,mixed>> a Propel ->toArray() shaped result */
    private function rows(): array
    {
        return [
            ['selDisplay' => 'Alpha', 'IdThing' => 3],
            ['selDisplay' => 'Beta',  'IdThing' => 7],
        ];
    }

    public function test_without_add_default_the_list_is_unchanged(): void
    {
        $this->assertSame(
            [['Alpha', 3], ['Beta', 7]],
            assocToNum($this->rows())
        );
    }

    public function test_add_default_prepends_one_blank_option(): void
    {
        $out = assocToNum($this->rows(), true);

        $this->assertCount(3, $out);
        $this->assertSame(['', '', ''], $out[0]);
        $this->assertSame([['Alpha', 3], ['Beta', 7]], array_slice($out, 1));
    }

    public function test_blank_option_is_NOT_rendered_by_the_gc_widget(): void
    {
        // C-3: the sentinel exists for the consumers that render the RAW array
        // (mobile client, API, screens.php). The gc widget has its own
        // `li.default` Clear row and its own placeholder, so optionListeSelect()
        // must skip it: rendering it drew an extra unlabelled clickable row AND
        // matched $selectedValue === '' on every create form, which killed the
        // placeholder fallback.
        $html = optionListeSelect(assocToNum($this->rows(), true), '', 'Category')['optionsList'];

        $this->assertStringNotContainsString('data-value=""', $html);
        $this->assertSame(2, substr_count($html, '<li'));
    }

    public function test_blank_option_leaves_the_placeholder_in_place(): void
    {
        // The bug: the sentinel matched the empty selected value, set
        // $selectedLabel to '', and `empty($selectedLabel)` therefore never
        // installed $defaultLabel — the closed label rendered blank.
        $out = optionListeSelect(assocToNum($this->rows(), true), '', 'Category');

        $this->assertSame('Category', $out['selectedLabel']);
    }

    public function test_a_real_selection_still_wins_over_the_placeholder(): void
    {
        $out = optionListeSelect(assocToNum($this->rows(), true), 7, 'Category');

        $this->assertSame('Beta', $out['selectedLabel']);
    }

    public function test_blank_option_carries_a_third_element(): void
    {
        // optionListeSelect() reads $option[2]; a 2-tuple would add an
        // "Undefined array key 2" warning to every render.
        $this->assertArrayHasKey(2, assocToNum($this->rows(), true)[0]);
    }

    public function test_empty_result_still_yields_the_blank_option(): void
    {
        $this->assertSame([['', '', '']], assocToNum([], true));
        $this->assertSame([], assocToNum([]));
    }

    public function test_sibling_variants_honour_the_flag_too(): void
    {
        $this->assertSame(['', '', ''], assocToNumV($this->rows(), true)[0]);
        $this->assertSame(['', '', ''], assocToNumDef($this->rows(), true)[0]);
        // assocToNumWidthNull used to hardcode false via `$addDefault = false`
        // in the argument it forwarded.
        $this->assertSame(['', '', ''], assocToNumWidthNull($this->rows(), true)[0]);
        $this->assertCount(2, assocToNumWidthNull($this->rows()));
    }
}
