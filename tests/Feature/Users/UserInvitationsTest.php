<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Models\UserInvitation;
use App\Support\UserInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserInvitationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Привремено, додека Задача 3 не ја внесе вистинската рута.
        // ТРГНИ ГО ОВОЈ БЛОК во Задача 3.
        if (! Route::has('invitation.accept')) {
            Route::get('invitation/{token}', fn () => '')->name('invitation.accept');
            // Именуваните рути се пребаруваат преку табела што се гради само
            // при подигање на апликацијата; рута додадена runtime мора рачно
            // да ја освежи за route() да ја пронајде во истиот тест.
            Route::getRoutes()->refreshNameLookups();
        }
    }

    public function test_a_fresh_invitation_sets_the_password_and_returns_the_user(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create(['password' => Hash::make('nema-vrska')]);

        $url = UserInvitations::issue($user, $admin);
        $token = basename(parse_url($url, PHP_URL_PATH));

        $accepted = UserInvitations::accept($token, 'nova-lozinka-123');

        $this->assertNotNull($accepted);
        $this->assertTrue($accepted->is($user));
        $this->assertTrue(Hash::check('nova-lozinka-123', $user->fresh()->password));
    }

    public function test_the_plain_token_is_never_stored(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $url = UserInvitations::issue($user, $admin);
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->assertDatabaseMissing('user_invitations', ['token_hash' => $token]);
        $this->assertDatabaseHas('user_invitations', ['token_hash' => hash('sha256', $token)]);
    }

    public function test_a_link_works_only_once(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $url = UserInvitations::issue($user, $admin);
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->assertNotNull(UserInvitations::accept($token, 'prva-lozinka-123'));
        $this->assertNull(UserInvitations::accept($token, 'vtora-lozinka-123'));
        $this->assertTrue(Hash::check('prva-lozinka-123', $user->fresh()->password));
    }

    public function test_a_link_older_than_seven_days_does_not_work(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $url = UserInvitations::issue($user, $admin);
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->travel(UserInvitations::DAYS_VALID + 1)->days();

        $this->assertNull(UserInvitations::accept($token, 'nova-lozinka-123'));
    }

    public function test_issuing_a_new_invitation_kills_the_previous_one(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $prv = basename(parse_url(UserInvitations::issue($user, $admin), PHP_URL_PATH));
        $vtor = basename(parse_url(UserInvitations::issue($user, $admin), PHP_URL_PATH));

        $this->assertNull(UserInvitations::accept($prv, 'nova-lozinka-123'));
        $this->assertNotNull(UserInvitations::accept($vtor, 'nova-lozinka-123'));
    }

    public function test_a_disabled_user_cannot_accept_an_invitation(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();

        $token = basename(parse_url(UserInvitations::issue($user, $admin), PHP_URL_PATH));

        $user->forceFill(['disabled_at' => now()])->save();

        $this->assertNull(UserInvitations::accept($token, 'nova-lozinka-123'));
    }

    public function test_access_status_reads_the_latest_invitation(): void
    {
        $admin = User::factory()->create();

        $active = User::factory()->create();
        $this->assertSame('active', $active->accessStatus());

        $disabled = User::factory()->create(['disabled_at' => now()]);
        $this->assertSame('disabled', $disabled->accessStatus());

        $invited = User::factory()->create();
        UserInvitations::issue($invited, $admin);
        $this->assertSame('invited', $invited->fresh()->accessStatus());

        $expired = User::factory()->create();
        UserInvitations::issue($expired, $admin);
        UserInvitation::query()->where('user_id', $expired->id)
            ->update(['expires_at' => now()->subDay()]);
        $this->assertSame('invitation_expired', $expired->fresh()->accessStatus());
    }
}
