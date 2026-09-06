<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Support\UserInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AcceptInvitationScreenTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        $url = UserInvitations::issue($user, User::factory()->create());

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_the_screen_opens_without_being_logged_in(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->get(route('invitation.accept', ['token' => $token]))
            ->assertOk()
            ->assertSee('Постави лозинка');
    }

    public function test_setting_a_password_logs_the_user_in(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        Volt::test('pages.auth.accept-invitation', ['token' => $token])
            ->set('password', 'nova-lozinka-123')
            ->set('password_confirmation', 'nova-lozinka-123')
            ->call('acceptInvitation')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_spent_link_shows_a_message_and_does_not_log_anybody_in(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        UserInvitations::accept($token, 'prva-lozinka-123');

        Volt::test('pages.auth.accept-invitation', ['token' => $token])
            ->set('password', 'vtora-lozinka-123')
            ->set('password_confirmation', 'vtora-lozinka-123')
            ->call('acceptInvitation')
            ->assertHasErrors('password');

        $this->assertGuest();
    }

    public function test_an_expired_link_shows_a_message(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->travel(UserInvitations::DAYS_VALID + 1)->days();

        Volt::test('pages.auth.accept-invitation', ['token' => $token])
            ->set('password', 'nova-lozinka-123')
            ->set('password_confirmation', 'nova-lozinka-123')
            ->call('acceptInvitation')
            ->assertHasErrors('password');

        $this->assertGuest();
    }

    public function test_the_password_must_be_confirmed(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        Volt::test('pages.auth.accept-invitation', ['token' => $token])
            ->set('password', 'nova-lozinka-123')
            ->set('password_confirmation', 'druga-lozinka-123')
            ->call('acceptInvitation')
            ->assertHasErrors('password');

        $this->assertGuest();
    }
}
