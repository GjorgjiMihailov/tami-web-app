<?php

namespace Tests\Feature;

use App\Livewire\Efaktura\IncomingCheckAll;
use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaIncomingCheckAllTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'accountant', 'internal_client', 'freelancer_client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function token(User $user): User
    {
        $user->forceFill(['efaktura_eujp_id' => 'EUJP-ACC', 'efaktura_token_serial_number' => 'AAA111'])->save();

        return $user->fresh();
    }

    public function test_it_lists_only_the_accountants_own_legal_clients_with_material(): void
    {
        $mine = Company::factory()->create(['name' => 'Мојата Фирма']);
        $noMaterial = Company::factory()->create(['name' => 'Без Материјално', 'uses_material' => false]);
        $individual = Company::factory()->create(['name' => 'Физичко Лице', 'type' => CompanyType::INDIVIDUAL]);
        $notMine = Company::factory()->create(['name' => 'Туѓа Фирма']);
        $accountant = $this->user('accountant');
        foreach ([$mine, $noMaterial, $individual] as $company) {
            $company->accountants()->attach($accountant);
        }

        Livewire::actingAs($accountant)->test(IncomingCheckAll::class)
            ->assertSee('Мојата Фирма')
            ->assertDontSee('Без Материјално')
            ->assertDontSee('Физичко Лице')
            ->assertDontSee('Туѓа Фирма');
    }

    public function test_without_a_personal_token_it_asks_to_register_one_and_offers_no_check(): void
    {
        $company = Company::factory()->create(['name' => 'Клиент']);
        $accountant = $this->user('accountant');
        $company->accountants()->attach($accountant);

        Livewire::actingAs($accountant)->test(IncomingCheckAll::class)
            ->assertSee('Немаш регистриран токен')
            ->assertSee('Клиент')
            ->assertSeeHtml(route('profile'))
            ->assertDontSee('Провери за сите клиенти');
    }

    public function test_with_a_token_it_offers_to_check_all_clients_and_hands_the_signer_serial_to_the_page(): void
    {
        $a = Company::factory()->create(['name' => 'Прва']);
        $b = Company::factory()->create(['name' => 'Втора']);
        $accountant = $this->user('accountant');
        $a->accountants()->attach($accountant);
        $b->accountants()->attach($accountant);

        Livewire::actingAs($this->token($accountant))->test(IncomingCheckAll::class)
            ->assertSee('Провери за сите клиенти (2)')
            ->assertSeeHtml('AAA111');
    }

    public function test_it_shows_how_many_undecided_documents_each_company_has(): void
    {
        $company = Company::factory()->create(['name' => 'Со Чекачки']);
        $accountant = $this->user('accountant');
        $company->accountants()->attach($accountant);
        foreach ([null, null, IncomingEfakturaDocument::DECISION_ACCEPTED] as $index => $decision) {
            IncomingEfakturaDocument::create([
                'company_id' => $company->id, 'euid' => 'EUID-'.$index, 'status_code' => '1', 'status_name' => 'Нова',
                'doc_number' => 'ФК-'.$index, 'doc_date' => '2026-03-01', 'seller_name' => 'Добавувач', 'seller_tax_id' => '4030001234567',
                'total_amount' => '100.00', 'payload_json' => [], 'discovered_at' => now(), 'decision' => $decision,
            ]);
        }

        // 2 неодлучени (третиот е прифатен и не се брои)
        $html = Livewire::actingAs($accountant)->test(IncomingCheckAll::class)->html();
        $this->assertMatchesRegularExpression('/Со Чекачки.*?>\s*2\s*</su', $html);
    }

    public function test_only_office_roles_may_open_it(): void
    {
        $company = Company::factory()->create();
        $client = $this->user('internal_client', ['company_id' => $company->id]);
        $freelancer = $this->user('freelancer_client');

        $this->actingAs($client)->get(route('efaktura.incoming-all'))->assertForbidden();
        $this->actingAs($freelancer)->get(route('efaktura.incoming-all'))->assertForbidden();
        $this->actingAs($this->user('admin'))->get(route('efaktura.incoming-all'))->assertOk();
        $this->actingAs($this->user('accountant'))->get(route('efaktura.incoming-all'))->assertOk();
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->get(route('efaktura.incoming-all'))->assertRedirect(route('login'));
    }

    public function test_the_portal_menu_links_to_the_incoming_check_for_the_office(): void
    {
        $this->actingAs($this->user('admin'))->get(route('dashboard'))
            ->assertSee('Влезни е-Фактури')
            ->assertSeeHtml(route('efaktura.incoming-all'))
            ->assertDontSeeHtml('href="'.route('efaktura.pending').'"');

        $accountant = $this->user('accountant');
        Company::factory()->create()->accountants()->attach($accountant);
        $this->actingAs($accountant)->get(route('companies.index'))
            ->assertSee('Влезни е-Фактури')
            ->assertSeeHtml(route('efaktura.incoming-all'));
    }
}
