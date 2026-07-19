<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_one_user_per_role_with_correct_scoping(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $admin = User::where('email', 'admin@tami.test')->first();
        $accountant = User::where('email', 'accountant@tami.test')->first();
        $client = User::where('email', 'client@tami.test')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('admin'));

        $this->assertNotNull($accountant);
        $this->assertTrue($accountant->hasRole('accountant'));
        $this->assertCount(2, $accountant->assignedCompanies);

        $this->assertNotNull($client);
        $this->assertTrue($client->hasRole('client'));
        $this->assertNotNull($client->company_id);
    }

    public function test_running_the_real_database_seeder_entry_point_seeds_the_chart_of_accounts_for_demo_companies(): void
    {
        // Deliberately uses the bare $this->seed() (DatabaseSeeder, the real
        // `php artisan db:seed` entry point) rather than seeding
        // DemoDataSeeder directly -- DatabaseSeeder used to wrap its run()
        // in Model::withoutEvents() via the WithoutModelEvents trait, which
        // silently suppressed Company's `created` event and meant demo
        // companies never got their chart of accounts seeded by
        // CompanyObserver. Seeding DemoDataSeeder directly (as the other
        // test in this file does) does not exercise that wrapper, so it
        // never caught this.
        //
        // DemoDataSeeder is also guarded behind app()->environment('local'),
        // which APP_ENV=testing (this suite's default) never satisfies --
        // force it so this test actually reaches DemoDataSeeder.
        $this->app['env'] = 'local';
        $this->seed();

        $companies = Company::all();

        $this->assertGreaterThan(0, $companies->count());

        foreach ($companies as $company) {
            $this->assertSame(
                428,
                Account::where('company_id', $company->id)->count(),
                "Company [{$company->name}] should have its chart of accounts seeded via the real db:seed entry point."
            );
        }
    }
}
