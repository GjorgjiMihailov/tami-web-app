<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_the_landing_screen_names_the_profile_type(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create([
            'name' => 'Марко Марковски',
            'type' => CompanyType::INDIVIDUAL,
        ]);

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Марко Марковски')
            ->assertSee('Физичко лице');
    }
}
