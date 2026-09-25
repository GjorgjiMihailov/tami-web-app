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

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
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
        $client->assignRole('internal_client');
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
            ->assertSee('Уреди')
            ->assertSeeHtml(route('partners.edit', [$company, $partner]));
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

    public function test_the_info_card_shows_existing_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $partner->bankAccounts()->create([
            'bank_name' => 'Комерцијална банка',
            'account_number' => 'MK07300701104789126',
            'position' => 0,
        ]);
        $partner->bankAccounts()->create([
            'bank_name' => 'НЛБ Банка',
            'account_number' => 'MK07200002785123453',
            'position' => 1,
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->assertSee('Трансакциски сметки')
            ->assertSee('Комерцијална банка')
            ->assertSee('MK07300701104789126')
            ->assertSee('НЛБ Банка')
            ->assertSee('MK07200002785123453');
    }

    public function test_the_info_card_shows_a_dash_when_no_bank_accounts_exist(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PartnerShow::class, ['company' => $company, 'partner' => $partner])
            ->assertSee('Трансакциски сметки')
            ->assertDontSee('MK0');
    }

}
