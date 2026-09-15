<?php

namespace Tests\Feature;

use App\Livewire\CompanyUsers;
use App\Livewire\OfficeUsers;
use App\Models\Company;
use App\Models\User;
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

        $this->assertSame(\Illuminate\Database\Eloquent\ModelNotFoundException::class, $outcome, 'Туѓ корисник треба да дава 404 (ModelNotFoundException), не 403.');
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
}
