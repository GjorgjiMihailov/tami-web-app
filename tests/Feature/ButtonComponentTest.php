<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ButtonComponentTest extends TestCase
{
    public function test_primary_button_uses_normal_case_medium_weight_label(): void
    {
        $html = Blade::render('<x-primary-button>Зачувај</x-primary-button>');

        $this->assertStringContainsString('font-semibold', $html);
        $this->assertStringContainsString('text-sm', $html);
        $this->assertStringNotContainsString('uppercase', $html);
        $this->assertStringNotContainsString('tracking-widest', $html);
    }

    public function test_secondary_button_uses_normal_case_medium_weight_label(): void
    {
        $html = Blade::render('<x-secondary-button>Откажи</x-secondary-button>');

        $this->assertStringNotContainsString('uppercase', $html);
        $this->assertStringNotContainsString('tracking-widest', $html);
    }

    public function test_danger_button_uses_normal_case_medium_weight_label(): void
    {
        $html = Blade::render('<x-danger-button>Избриши</x-danger-button>');

        $this->assertStringNotContainsString('uppercase', $html);
        $this->assertStringNotContainsString('tracking-widest', $html);
    }

    public function test_all_three_buttons_share_the_same_pill_radius(): void
    {
        $primary = Blade::render('<x-primary-button>A</x-primary-button>');
        $secondary = Blade::render('<x-secondary-button>B</x-secondary-button>');
        $danger = Blade::render('<x-danger-button>C</x-danger-button>');

        $this->assertStringContainsString('rounded-full', $primary);
        $this->assertStringContainsString('rounded-full', $secondary);
        $this->assertStringContainsString('rounded-full', $danger);
    }
}
