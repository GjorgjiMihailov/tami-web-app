<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ResponsiveNavLinkComponentTest extends TestCase
{
    public function test_inactive_responsive_nav_link_has_a_warm_hover(): void
    {
        $html = Blade::render('<x-responsive-nav-link href="#">Профил</x-responsive-nav-link>');

        $this->assertStringContainsString('hover:bg-orange-50', $html);
    }

    public function test_active_responsive_nav_link_keeps_its_brand_accent(): void
    {
        $html = Blade::render('<x-responsive-nav-link href="#" :active="true">Профил</x-responsive-nav-link>');

        $this->assertStringContainsString('border-brand', $html);
        $this->assertStringContainsString('bg-orange-50', $html);
    }
}
