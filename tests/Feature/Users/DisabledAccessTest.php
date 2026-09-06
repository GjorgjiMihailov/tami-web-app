<?php

namespace Tests\Feature\Users;

use App\Livewire\Forms\LoginForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DisabledAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('tajna-lozinka'),
        ], $attributes));
    }

    public function test_an_active_user_can_log_in(): void
    {
        $user = $this->user();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'tajna-lozinka')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_disabled_user_cannot_log_in(): void
    {
        $user = $this->user(['disabled_at' => now()]);

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'tajna-lozinka')
            ->call('login')
            ->assertHasErrors('form.email');

        $this->assertGuest();
    }

    public function test_a_user_disabled_mid_session_is_logged_out_on_the_next_click(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['disabled_at' => now()])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
