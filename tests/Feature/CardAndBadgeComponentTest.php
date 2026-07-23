<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class CardAndBadgeComponentTest extends TestCase
{
    public function test_card_renders_its_slot_with_rounded_style(): void
    {
        $html = Blade::render('<x-card>Hello</x-card>');

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('rounded-2xl', $html);
    }

    public function test_card_padding_prop_overrides_default_padding_without_conflicting_classes(): void
    {
        $html = Blade::render('<x-card padding="p-0">Hello</x-card>');

        $this->assertStringContainsString('p-0', $html);
        $this->assertStringNotContainsString('p-4', $html);
    }

    public function test_badge_maps_confirmed_and_paid_to_green(): void
    {
        $confirmed = Blade::render('<x-badge status="confirmed">Confirmed</x-badge>');
        $paid = Blade::render('<x-badge status="paid">Paid</x-badge>');

        $this->assertStringContainsString('bg-green-100', $confirmed);
        $this->assertStringContainsString('bg-green-100', $paid);
    }

    public function test_badge_maps_draft_and_unpaid_to_amber(): void
    {
        $draft = Blade::render('<x-badge status="draft">Draft</x-badge>');
        $unpaid = Blade::render('<x-badge status="unpaid">Unpaid</x-badge>');

        $this->assertStringContainsString('bg-amber-100', $draft);
        $this->assertStringContainsString('bg-amber-100', $unpaid);
    }

    public function test_badge_maps_cancelled_and_overdue_to_red(): void
    {
        $cancelled = Blade::render('<x-badge status="cancelled">Cancelled</x-badge>');
        $overdue = Blade::render('<x-badge status="overdue">Overdue</x-badge>');

        $this->assertStringContainsString('bg-red-100', $cancelled);
        $this->assertStringContainsString('bg-red-100', $overdue);
    }

    public function test_badge_falls_back_to_gray_for_an_unknown_status(): void
    {
        $html = Blade::render('<x-badge status="something-else">Something</x-badge>');

        $this->assertStringContainsString('bg-gray-100', $html);
    }
}
