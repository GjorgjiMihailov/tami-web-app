<?php

namespace Tests\Feature;

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
}
