<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\CompanyType;
use App\Support\LandingUrl;
use App\Support\PortalApp;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Админот не бира фирма при најава — има сопствено табло: вкупни бројки,
 * сметководители и лимити, состојба на сметките и активност.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'internal_client', 'freelancer_client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function test_an_admin_lands_on_their_own_dashboard_not_a_company_picker(): void
    {
        Company::factory()->create(['name' => 'Solo Ltd']);

        $this->actingAs($this->userWithRole('admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Табло')
            ->assertDontSee('Изберете фирма')
            ->assertDontSee('Solo Ltd');
    }

    public function test_it_has_the_two_quick_buttons(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('dashboard'))
            ->assertSee('Нова фирма')
            ->assertSee('Нова сметка на канцеларија')
            ->assertSeeHtml(route('companies.index'))
            ->assertSeeHtml(route('companies.office'));
    }

    public function test_an_admin_is_never_sent_into_a_company_even_when_only_one_exists(): void
    {
        Company::factory()->create();
        $admin = $this->userWithRole('admin');

        foreach ([PortalApp::PRODAZBA, PortalApp::FINANSII, PortalApp::PLATA, PortalApp::PORTAL, null] as $app) {
            $this->assertSame(route('dashboard'), LandingUrl::for($admin, $app));
        }
    }

    public function test_the_totals_count_companies_and_accounts(): void
    {
        Company::factory()->count(2)->create();
        Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $this->userWithRole('accountant');
        $this->userWithRole('accountant');
        $this->userWithRole('internal_client');
        $this->userWithRole('freelancer_client');
        $admin = $this->userWithRole('admin');

        Livewire::actingAs($admin)->test(Dashboard::class)
            ->assertViewHas('totals', fn (array $t) => $t['companies'] === 3
                && $t['legal'] === 2
                && $t['individual'] === 1
                && $t['accountants'] === 2
                && $t['admins'] === 1
                && $t['internalClients'] === 1
                && $t['freelancers'] === 1);
    }

    public function test_it_lists_accountants_with_their_company_count_and_limit(): void
    {
        $accountant = $this->userWithRole('accountant', ['name' => 'Ана Стојанова', 'company_limit' => 2]);
        Company::factory()->count(2)->create()->each(fn (Company $c) => $c->accountants()->attach($accountant));

        Livewire::actingAs($this->userWithRole('admin'))->test(Dashboard::class)
            ->assertSee('Ана Стојанова')
            ->assertSee('Лимитот е достигнат')
            ->assertViewHas('accountants', fn ($list) => $list->first()->assigned_companies_count === 2);
    }

    public function test_the_admin_changes_a_limit_from_the_dashboard(): void
    {
        $accountant = $this->userWithRole('accountant');

        Livewire::actingAs($this->userWithRole('admin'))->test(Dashboard::class)
            ->call('updateCompanyLimit', $accountant->id, '4');

        $this->assertSame(4, $accountant->fresh()->company_limit);
    }

    public function test_an_accountant_cannot_change_a_limit_through_the_dashboard(): void
    {
        // Сметководител со две фирми го отвора истиот компонент (избирачот),
        // па дејството мора да го одбие самото.
        $accountant = $this->userWithRole('accountant');
        Company::factory()->count(2)->create()->each(fn (Company $c) => $c->accountants()->attach($accountant));

        Livewire::actingAs($accountant)->test(Dashboard::class)
            ->call('updateCompanyLimit', $accountant->id, '9')
            ->assertForbidden();

        $this->assertNull($accountant->fresh()->company_limit);
    }

    public function test_it_shows_the_accounts_that_need_attention(): void
    {
        $this->userWithRole('internal_client', ['name' => 'Исклучен Клиент', 'disabled_at' => now()]);
        $expired = $this->userWithRole('accountant', ['name' => 'Истечена Покана']);
        UserInvitation::create([
            'user_id' => $expired->id,
            'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->subDay(),
        ]);
        $this->userWithRole('accountant', ['name' => 'Активна Сметка']);

        // Листата „бараат внимание" мора да ги има точно двете, а не и активната
        // (која сепак се појавува во табелата на сметководители).
        Livewire::actingAs($this->userWithRole('admin'))->test(Dashboard::class)
            ->assertViewHas('needAttention', fn ($list) => $list->pluck('name')->sort()->values()->all()
                === ['Исклучен Клиент', 'Истечена Покана'])
            ->assertSee('Поканата истече')
            ->assertSee('Исклучен')
            ->assertViewHas('statusCounts', fn ($counts) => $counts->get('disabled') === 1
                && $counts->get('invitation_expired') === 1);
    }

    public function test_it_shows_who_is_active_now_from_the_session_table(): void
    {
        config(['session.driver' => 'database']);
        $active = $this->userWithRole('accountant', ['name' => 'Сега Активен']);
        $idle = $this->userWithRole('accountant', ['name' => 'Одамна Отсутен']);

        DB::table('sessions')->insert([
            ['id' => 'a1', 'user_id' => $active->id, 'payload' => '', 'last_activity' => now()->subMinutes(5)->getTimestamp()],
            ['id' => 'a2', 'user_id' => $idle->id, 'payload' => '', 'last_activity' => now()->subHours(3)->getTimestamp()],
        ]);

        Livewire::actingAs($this->userWithRole('admin'))->test(Dashboard::class)
            ->assertViewHas('activeNow', fn ($list) => $list->pluck('id')->all() === [$active->id]);
    }

    public function test_it_says_so_when_sessions_are_not_kept_in_the_database(): void
    {
        config(['session.driver' => 'file']);

        Livewire::actingAs($this->userWithRole('admin'))->test(Dashboard::class)
            ->assertSee('Активните сесии не се чуваат во база');
    }

    public function test_a_login_is_recorded_and_shown(): void
    {
        $person = $this->userWithRole('internal_client', ['name' => 'Марија Клиент']);
        $this->assertNull($person->last_login_at);

        event(new Login('web', $person, false));

        $this->assertNotNull($person->fresh()->last_login_at);

        Livewire::actingAs($this->userWithRole('admin'))->test(Dashboard::class)
            ->assertSee('Марија Клиент')
            ->assertViewHas('recentLogins', fn ($list) => $list->pluck('id')->contains($person->id));
    }

    public function test_it_says_logins_are_recorded_from_today_when_there_is_no_data_yet(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))->test(Dashboard::class)
            ->assertSee('Најавите се бележат од денес');
    }
}
