<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ModalAndDropdownComponentTest extends TestCase
{
    public function test_modal_panel_uses_the_same_rounded_2xl_radius_as_cards(): void
    {
        $html = Blade::render('<x-modal name="test">Content</x-modal>');

        $this->assertStringContainsString('rounded-2xl', $html);
        $this->assertStringNotContainsString('rounded-lg', $html);
    }

    public function test_dropdown_panel_uses_rounded_xl(): void
    {
        $html = Blade::render('<x-dropdown><x-slot name="trigger">T</x-slot><x-slot name="content">C</x-slot></x-dropdown>');

        $this->assertStringContainsString('rounded-xl', $html);
    }

    public function test_dropdown_link_has_a_warm_hover_instead_of_plain_gray(): void
    {
        $html = Blade::render('<x-dropdown-link href="#">Профил</x-dropdown-link>');

        $this->assertStringContainsString('hover:bg-orange-50', $html);
    }
}
