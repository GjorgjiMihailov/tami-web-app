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

    public function test_starting_edit_seeds_one_blank_bank_account_row_when_none_exist(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->assertSet('bankAccounts', [['bank_name' => '', 'account_number' => '']]);
    }

    public function test_filling_the_last_bank_account_row_reveals_a_new_blank_row(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->set('bankAccounts.0.account_number', 'MK07300701104789126');

        $this->assertCount(2, $component->get('bankAccounts'));
    }

    public function test_bank_account_rows_are_capped_at_five(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit');

        foreach (range(0, 4) as $index) {
            $component->set("bankAccounts.$index.account_number", "MK0{$index}00000000000000");
        }

        $this->assertCount(5, $component->get('bankAccounts'));
    }

    public function test_admin_can_save_multiple_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->set('bankAccounts.0.bank_name', 'Комерцијална банка')
            ->set('bankAccounts.0.account_number', 'MK07300701104789126')
            ->set('bankAccounts.1.bank_name', 'НЛБ Банка')
            ->set('bankAccounts.1.account_number', 'MK07200002785123453')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('partner_bank_accounts', [
            'partner_id' => $partner->id,
            'bank_name' => 'Комерцијална банка',
            'account_number' => 'MK07300701104789126',
            'position' => 0,
        ]);
        $this->assertDatabaseHas('partner_bank_accounts', [
            'partner_id' => $partner->id,
            'bank_name' => 'НЛБ Банка',
            'account_number' => 'MK07200002785123453',
            'position' => 1,
        ]);
        $this->assertDatabaseCount('partner_bank_accounts', 2);
    }

    public function test_saving_replaces_the_previous_set_of_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $partner->bankAccounts()->create(['bank_name' => 'Стара банка', 'account_number' => 'MK00OLD00000000000', 'position' => 0]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->assertSet('bankAccounts.0.account_number', 'MK00OLD00000000000')
            ->set('bankAccounts.0.bank_name', 'Нова банка')
            ->set('bankAccounts.0.account_number', 'MK00NEW00000000000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('partner_bank_accounts', ['account_number' => 'MK00OLD00000000000']);
        $this->assertDatabaseHas('partner_bank_accounts', [
            'partner_id' => $partner->id,
            'bank_name' => 'Нова банка',
            'account_number' => 'MK00NEW00000000000',
        ]);
    }

    public function test_a_blank_trailing_row_is_not_saved(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->call('startEdit')
            ->set('bankAccounts.0.bank_name', 'Комерцијална банка')
            ->set('bankAccounts.0.account_number', 'MK07300701104789126')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('partner_bank_accounts', 1);
    }
}
