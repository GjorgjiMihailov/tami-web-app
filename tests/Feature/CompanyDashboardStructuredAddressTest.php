<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardStructuredAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_admin_can_save_structured_address_fields(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create();

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editStreetAddress', 'Мајка Тереза')
            ->set('editStreetNumber', '12')
            ->set('editPostalCode', '1000')
            ->set('editCity', 'Скопје')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame('Мајка Тереза', $company->street_address);
        $this->assertSame('12', $company->street_number);
        $this->assertSame('1000', $company->postal_code);
        $this->assertSame('Скопје', $company->city);
    }
}
