<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MotionKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_the_shared_layout_carries_the_motion_hook(): void
    {
        // Самото движење живее во resources/css/app.css. Изгледот има една
        // задача: да ја носи куката на едно место, за сите 15 списоци.
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('app-main', false);
    }

    public function test_the_entrance_switch_turns_itself_off(): void
    {
        // Редовите во списоците во најголем дел немаат wire:key, па Livewire
        // ги заменува при секое освежување. Без прекинувач што се гаси,
        // табелата би влегувала одново при секоја буква во полето за барање.
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertSee("classList.add('motion-in')", false);
        $response->assertSee("classList.remove('motion-in')", false);
    }

    public function test_every_delayed_animation_is_switched_off_for_reduced_motion(): void
    {
        // Општото правило на дното на app.css само ја скратува траењето на
        // 0.01ms. Анимација со animation-delay и backwards под него останува
        // НЕВИДЛИВА додека чека, па мора да стои и во блокот со animation:none.
        $css = file_get_contents(resource_path('css/app.css'));

        $reducedBlock = substr($css, (int) strpos($css, 'prefers-reduced-motion'));

        foreach (['.app-tile', '.board-link', '.app-card', '.app-main.motion-in table tbody tr'] as $selector) {
            $this->assertStringContainsString(
                $selector,
                $reducedBlock,
                "Анимацијата на {$selector} носи задоцнување и мора да се гаси изречно."
            );
        }
    }
}
