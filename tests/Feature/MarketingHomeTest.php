<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_shows_the_public_landing_page_to_a_guest(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('ТАМИ', false);
        $response->assertSee('Сметководството на твојата фирма', false);
    }

    public function test_a_guest_is_offered_the_login_link(): void
    {
        $response = $this->get('/');

        $response->assertSee('Најави се', false);
        $response->assertSee(route('login'), false);
        $response->assertDontSee('Влези во порталот', false);
    }

    /**
     * Најавен корисник не се пренасочува: страницата останува отворена, само
     * копчињата водат внатре наместо на најава.
     */
    public function test_a_signed_in_user_is_pointed_at_the_portal_instead(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertOk();
        $response->assertSee('Влези во порталот', false);
        $response->assertSee(route('dashboard'), false);
    }

    /**
     * Сликите се дел од страницата, не украс — ако некоја падне од public/,
     * херојот останува празен правоаголник, а тоа никој нема да го забележи
     * без тест.
     */
    public function test_the_screenshots_it_points_at_actually_exist(): void
    {
        $this->get('/')->assertOk();

        foreach (['dashboard', 'sales-invoices', 'payroll'] as $screen) {
            $this->assertFileExists(
                public_path("images/screens/{$screen}.png"),
                "Недостига сликата на екранот: {$screen}.png"
            );
        }

        $this->assertFileExists(public_path('images/logo-icon.png'));
    }
}
