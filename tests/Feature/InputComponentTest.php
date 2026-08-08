<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class InputComponentTest extends TestCase
{
    public function test_text_input_has_a_visible_transition_on_its_focus_ring(): void
    {
        $html = Blade::render('<x-text-input />');

        $this->assertStringContainsString('focus:border-brand', $html);
        $this->assertStringContainsString('focus:ring-brand', $html);
        $this->assertStringContainsString('transition', $html);
    }

    public function test_input_label_uses_medium_gray_for_a_softer_look_than_pure_gray_700(): void
    {
        $html = Blade::render('<x-input-label value="Назив" />');

        $this->assertStringContainsString('text-gray-600', $html);
    }
}
