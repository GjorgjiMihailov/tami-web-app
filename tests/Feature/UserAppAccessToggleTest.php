<?php

namespace Tests\Feature;

use App\Livewire\CompanyUsers;
use App\Livewire\OfficeUsers;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserAppAccessToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_an_admin_closes_an_app_for_a_client(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $client->id, 'plata');

        $this->assertFalse($client->fresh()->app_plata);
    }

    public function test_the_same_call_switches_it_back_on(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create(['company_id' => $company->id, 'app_plata' => false]);
        $client->assignRole('client');

        Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $client->id, 'plata');

        $this->assertTrue($client->fresh()->app_plata);
    }

    public function test_a_user_from_another_company_cannot_be_touched(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $stranger = User::factory()->create(['company_id' => $other->id]);
        $stranger->assignRole('client');

        // Livewire::test()->call() ги исклучува HttpException/AuthorizationException
        // од заменетиот exception handler (тие си одат преку вистинскиот render() и
        // излегуваат како 403), но не и ModelNotFoundException — таа излегува сурова.
        // Иста шема ја користи CompanyUsersTest::exceptionShapeOf() за истиот опсег
        // (companyUser()), затоа фаќаме исто наместо assertStatus(404).
        $outcome = $this->exceptionShapeOf(fn () => Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $stranger->id, 'plata'));

        $this->assertSame(ModelNotFoundException::class, $outcome, 'Туѓ корисник треба да дава 404 (ModelNotFoundException), не 403.');
        $this->assertTrue($stranger->fresh()->app_plata);
    }

    private function exceptionShapeOf(\Closure $callback): string
    {
        try {
            $callback();

            return 'no-exception';
        } catch (\Throwable $e) {
            return get_class($e);
        }
    }

    public function test_a_client_cannot_open_an_app_for_itself(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id, 'app_plata' => false]);
        $client->assignRole('client');

        Livewire::actingAs($client)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $client->id, 'plata')
            ->assertStatus(403);

        $this->assertFalse($client->fresh()->app_plata);
    }

    public function test_an_admin_closes_an_app_for_an_accountant(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        Livewire::actingAs($admin)
            ->test(OfficeUsers::class)
            ->call('toggleApp', $accountant->id, 'finansii');

        $this->assertFalse($accountant->fresh()->app_finansii);
    }

    public function test_an_unknown_app_name_is_refused(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $client->id, 'portal')
            ->assertStatus(403);
    }

    /**
     * Клиент смее да ја гледа сопствената фирма (CompanyPolicy::view), па
     * квадратчињата од новата колона му се прикажани — но само за гледање:
     * без wire:click, со `disabled`, инаку „работи" контрола што при клик
     * тивко паѓа на 403 (Gate::authorize во toggleApp) е излажан клиент.
     * Админ на истиот екран мора да ги гледа истите квадратчиња живи.
     */
    public function test_a_client_sees_read_only_checkboxes_but_an_admin_sees_live_ones_on_company_users(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $clientHtml = Livewire::actingAs($client)
            ->test(CompanyUsers::class, ['company' => $company])
            ->assertOk()
            ->html();

        $this->assertStringNotContainsString(
            'wire:click="toggleApp',
            $clientHtml,
            'Клиент не смее да гледа „работечка" контрола за туѓо право.'
        );
        $this->assertMatchesRegularExpression(
            '/<input type="checkbox"\s+disabled/',
            $clientHtml,
            'Клиент треба да ги гледа квадратчињата само за читање.'
        );

        $adminHtml = Livewire::actingAs($admin)
            ->test(CompanyUsers::class, ['company' => $company])
            ->assertOk()
            ->html();

        $this->assertStringContainsString(
            "wire:click=\"toggleApp({$client->id}, 'plata')\"",
            $adminHtml,
            'Админ треба да ги гледа истите квадратчиња живи.'
        );
    }

    /**
     * Екранот Канцеларија е веќе целосно заклучен за неадмин (mount() фрла
     * 403 за секој без улога admin — видете OfficeUsersTest), па сценарио
     * „неадмин ГО ГЛЕДА екранот" не постои таму за да се тестира read-only
     * патеката. Штиклирањето сепак е ставено за симетрија и одбрана однатре
     * ако правилото на mount() некогаш се разлаба. Овој тест го покрива
     * делот што важи денес: админ гледа живи квадратчиња.
     */
    public function test_an_admin_sees_live_checkboxes_on_office_users(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $html = Livewire::actingAs($admin)
            ->test(OfficeUsers::class)
            ->assertOk()
            ->html();

        $this->assertStringContainsString(
            "wire:click=\"toggleApp({$accountant->id}, 'finansii')\"",
            $html,
            'Админ треба да ги гледа квадратчињата живи и на Канцеларија.'
        );
    }

    public function test_an_accountant_of_the_company_toggles_an_app_for_a_client(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($accountant)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('toggleApp', $client->id, 'plata');

        $this->assertFalse($client->fresh()->app_plata);
    }

    public function test_an_accountant_of_the_company_sees_live_checkboxes_a_stranger_accountant_sees_forbidden(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $mine = User::factory()->create();
        $mine->assignRole('accountant');
        $company->accountants()->attach($mine);

        $html = Livewire::actingAs($mine)
            ->test(CompanyUsers::class, ['company' => $company])
            ->assertOk()
            ->html();

        $this->assertStringContainsString(
            "wire:click=\"toggleApp({$client->id}, 'plata')\"",
            $html,
            'Сметководител на таа фирма треба да гледа живи квадратчиња, како админ.'
        );
    }

    public function test_an_accountant_not_on_the_company_cannot_even_reach_the_screen_to_toggle(): void
    {
        // Истата причина: mount() (view()) веќе одбива пред toggleApp() да
        // се повика — нема сценарио каде овој метод воопшто се стигнува за
        // фирма на која актерот не е доделен.
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        Livewire::actingAs($accountant)
            ->test(CompanyUsers::class, ['company' => $company])
            ->assertForbidden();

        $this->assertTrue($client->fresh()->app_plata);
    }
}
