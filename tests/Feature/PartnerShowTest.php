<?php

namespace Tests\Feature;

use App\Livewire\PartnerShow;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerShowTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_shows_the_partners_details_and_document_manager(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme Supplies']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('partners.show', [$company, $partner]))
            ->assertOk()
            ->assertSee('Acme Supplies')
            ->assertSeeLivewire('document-manager');
    }

    public function test_a_client_cannot_view_another_companys_partner(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $partner = Partner::factory()->for($otherCompany)->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get(route('partners.show', [$otherCompany, $partner]))->assertForbidden();
    }

    public function test_the_partner_index_links_to_the_show_page(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('partners.index', $company))
            ->assertOk()
            ->assertSee(route('partners.show', [$company, $partner]), false);
    }

    public function test_a_user_with_access_sees_the_edit_button(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->assertSee('Уреди');
    }

    public function test_a_client_of_the_same_company_can_edit_a_partner(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->set('editName', 'Ажурирано име')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('partners', ['id' => $partner->id, 'name' => 'Ажурирано име']);
    }

    public function test_editing_the_partner_requires_a_name(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->set('editName', '')
            ->call('save')
            ->assertHasErrors(['editName' => 'required']);
    }

    public function test_admin_can_edit_a_legal_entitys_full_profile(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['type' => 'legal_entity']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->set('editRegistrationNumber', '7080123')
            ->set('editDirectorName', 'Марко Марковски')
            ->set('editIsVatRegistered', true)
            ->set('editVatNumber', 'MK4030012345678')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('partners', [
            'id' => $partner->id,
            'registration_number' => '7080123',
            'director_name' => 'Марко Марковски',
            'is_vat_registered' => true,
            'vat_number' => 'MK4030012345678',
        ]);
    }

    public function test_legal_entity_fields_are_cleared_when_switched_to_individual(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create([
            'type' => 'legal_entity',
            'registration_number' => '7080123',
            'director_name' => 'Марко Марковски',
            'is_vat_registered' => true,
            'vat_number' => 'MK4030012345678',
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        // Deliberately still setting the legal-entity fields on the component AFTER
        // switching the type, to prove save()'s own server-side guard clears them —
        // not merely that the blade @if hides the inputs client-side.
        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->set('editType', 'individual')
            ->set('editRegistrationNumber', '7080123')
            ->set('editDirectorName', 'Марко Марковски')
            ->set('editIsVatRegistered', true)
            ->set('editVatNumber', 'MK4030012345678')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('partners', [
            'id' => $partner->id,
            'type' => 'individual',
            'registration_number' => null,
            'director_name' => null,
            'is_vat_registered' => false,
            'vat_number' => null,
        ]);
    }

    public function test_vat_number_is_cleared_when_vat_registered_is_unchecked(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create([
            'type' => 'legal_entity',
            'is_vat_registered' => true,
            'vat_number' => 'MK4030012345678',
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->set('editIsVatRegistered', false)
            ->set('editVatNumber', 'MK4030012345678')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('partners', [
            'id' => $partner->id,
            'is_vat_registered' => false,
            'vat_number' => null,
        ]);
    }

    public function test_the_info_card_shows_type_and_legal_entity_fields(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create([
            'type' => 'legal_entity',
            'registration_number' => '7080123',
            'director_name' => 'Марко Марковски',
            'is_vat_registered' => true,
            'vat_number' => 'MK4030012345678',
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->assertSee('Правно лице')
            ->assertSee('7080123')
            ->assertSee('Марко Марковски')
            ->assertSee('MK4030012345678');
    }

    public function test_the_info_card_hides_legal_entity_fields_for_an_individual(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->individual()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->assertSee('Физичко лице')
            ->assertDontSee('ЕМБС');
    }
}
