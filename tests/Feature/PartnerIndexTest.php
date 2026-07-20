<?php

namespace Tests\Feature;

use App\Livewire\PartnerIndex;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_partners(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Acme DOOEL']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->assertSee('Acme DOOEL');
    }

    public function test_client_can_add_a_partner_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->set('newName', 'Beta Customer DOO')
            ->set('newTaxId', '4001234567890')
            ->set('newEmail', 'billing@betacustomer.mk')
            ->call('addPartner')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('partners', [
            'company_id' => $company->id,
            'name' => 'Beta Customer DOO',
            'tax_id' => '4001234567890',
        ]);
    }

    public function test_adding_a_partner_requires_a_name(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->set('newName', '')
            ->call('addPartner')
            ->assertHasErrors(['newName' => 'required']);
    }

    public function test_the_partners_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('partners.index', $company))
            ->assertOk();
    }
}
