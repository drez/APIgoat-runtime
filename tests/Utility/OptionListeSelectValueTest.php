<?php

use PHPUnit\Framework\TestCase;

if (!defined('_MESS_SELECTION')) { define('_MESS_SELECTION', 'Selection'); }
require_once __DIR__ . '/../../src/Utility/Legacy/html_helper.php';

/**
 * T11 — optionListeSelect() used to run
 *   $option[1] = (empty($option[1]) ? $option[0] : $option[1]);
 * so ANY falsy option value ('0', 0, false) was replaced by its label: a
 * [["Yes",1],["No",0]] list rendered "No" with data-value="No", and selecting
 * it posted the label instead of 0.
 */
final class OptionListeSelectValueTest extends TestCase
{
    public function test_zero_value_is_not_collapsed_onto_its_label(): void
    {
        $html = optionListeSelect([['Yes', 1], ['No', 0]], '', '')['optionsList'];

        $this->assertStringContainsString('data-value="0"', $html);
        $this->assertStringNotContainsString('data-value="No"', $html);
    }

    public function test_string_zero_value_is_not_collapsed_either(): void
    {
        $html = optionListeSelect([['Off', '0']], '', '')['optionsList'];

        $this->assertStringContainsString('data-value="0"', $html);
    }

    public function test_zero_value_can_be_selected(): void
    {
        $out = optionListeSelect([['Yes', 1], ['No', 0]], 0, '');

        $this->assertSame('No', $out['selectedLabel']);
    }

    public function test_missing_value_slot_still_falls_back_to_the_label(): void
    {
        $html = optionListeSelect([['Alpha']], '', '')['optionsList'];

        $this->assertStringContainsString('data-value="Alpha"', $html);
    }

    public function test_empty_string_value_still_falls_back_to_the_label(): void
    {
        // The blank "clear" option assocToNum($rows, true) prepends is
        // ['', '', ''] — label and value both empty, so this stays data-value="".
        $html = optionListeSelect([['', '', '']], '', '')['optionsList'];

        $this->assertStringContainsString('data-value=""', $html);
    }
}
