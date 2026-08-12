<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_page_body_uses_the_canvas_background(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('min-h-screen flex bg-canvas', false);
    }

    public function test_sidebar_is_light_not_dark(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('bg-white border-r border-gray-100', false);
    }

    public function test_the_sidebar_is_an_off_canvas_drawer_driven_by_one_alpine_flag(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        // The drawer behaviour itself lives in resources/css/app.css under a
        // max-width:1023px media query; the markup only has to carry the hook
        // class and the open-state binding.
        $response->assertSee('app-sidebar', false);
        $response->assertSee("'is-open': sidebarOpen", false);
        $response->assertSee('x-data="{ sidebarOpen: false }"', false);
    }

    public function test_there_is_a_toggle_to_open_the_drawer_and_one_to_close_it(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Отвори мени', false);
        $response->assertSee('Затвори мени', false);
        $response->assertSee('sidebarOpen = true', false);
        $response->assertSee('sidebarOpen = false', false);
    }

    public function test_the_drawer_controls_and_backdrop_disappear_at_the_large_breakpoint(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        // The backdrop is mobile-only; on a desktop the sidebar is a permanent
        // column and nothing may sit on top of the page.
        $response->assertSee('bg-gray-900/50 lg:hidden', false);
    }
}
