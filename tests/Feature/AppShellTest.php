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
        $response->assertSee('bg-canvas', false);
        $response->assertDontSee('bg-gray-50', false);
    }

    public function test_sidebar_is_light_not_dark(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('bg-white border-r border-gray-100', false);
        $response->assertDontSee('bg-gray-800', false);
    }
}
