<?php

namespace Tests\Feature;

use App\Livewire\PartnerForm;
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

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    public function test_it_lists_the_companys_partners(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Acme DOOEL']);
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->assertSee('Acme DOOEL')
            ->assertSee('+ Нов кооперант');
    }

    public function test_the_partners_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        $this->get(route('partners.index', $company))->assertOk();
        $this->get(route('partners.create', $company))->assertOk();
    }

    public function test_with_no_partners_the_page_shows_the_empty_state_and_no_table_or_entry_form(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->assertSee('Секоја продажба почнува со кооперант')
            ->assertSeeHtml(route('partners.create', $company))
            ->assertDontSee('<table', false)
            ->assertDontSee('Преземи PDF')
            ->assertDontSeeHtml('wire:submit');
    }

    public function test_the_list_page_has_no_inline_entry_form(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create();
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->assertDontSeeHtml('wire:submit')
            ->assertDontSee('Секоја продажба почнува со кооперант');
    }

    public function test_client_can_add_a_partner_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');
        $this->actingAs($client);

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('name', 'Beta Customer DOO')
            ->set('taxId', '4001234567890')
            ->set('email', 'billing@betacustomer.mk')
            ->call('save')
            ->assertHasNoErrors();

        $partner = Partner::where('company_id', $company->id)->first();
        $this->assertSame('Beta Customer DOO', $partner->name);
        $this->assertSame('4001234567890', $partner->tax_id);
        $this->assertSame('legal_entity', $partner->type);
    }

    public function test_saving_redirects_to_the_new_partner(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        $component = Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('name', 'Бета ДООЕЛ')
            ->call('save');

        $partner = Partner::where('name', 'Бета ДООЕЛ')->first();
        $component->assertRedirect(route('partners.show', [$company, $partner]));
    }

    public function test_the_form_persists_the_selected_type(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('name', 'Марко Петровски')
            ->set('type', 'individual')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('partners', ['company_id' => $company->id, 'name' => 'Марко Петровски', 'type' => 'individual']);
    }

    public function test_a_name_is_required_and_the_email_must_be_valid(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('name', '')
            ->set('email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'email' => 'email']);
    }

    public function test_the_partner_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Acme DOOEL']);
        $this->admin();

        Livewire::test(PartnerIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
}
