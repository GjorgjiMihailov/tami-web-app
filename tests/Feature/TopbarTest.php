<?php

namespace Tests\Feature;

use App\Livewire\Layout\Bell;
use App\Livewire\Layout\GlobalSearch;
use App\Models\Company;
use App\Models\Form743;
use App\Models\IncomingEfakturaDocument;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\User;
use App\Support\CompanyType;
use App\Support\Menu;
use App\Support\WorkingYear;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Горната лента: брзите дејства „+ Нова", пребарувањето и ѕвончето.
 * Секое од нив смее да нуди само она што менито веќе го дозволува.
 */
class TopbarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function labels(User $user, Company $company): array
    {
        return array_column(Menu::quickCreate($user, $company), 'label');
    }

    public function test_a_legal_entity_gets_all_the_quick_actions_for_its_modules(): void
    {
        $company = Company::factory()->create(['uses_material' => true, 'uses_stock' => true, 'uses_payroll' => true]);

        $this->assertSame(
            ['Излезна фактура', 'Профактура', 'Влезна фактура', 'Кооперант', 'Артикл', 'Вработен'],
            $this->labels($this->admin(), $company),
        );
    }

    public function test_an_individual_gets_no_purchase_invoice_item_or_employee_action(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        $this->assertSame(['Излезна фактура', 'Профактура', 'Кооперант'], $this->labels($this->admin(), $company));
    }

    public function test_a_switched_off_module_hides_its_actions(): void
    {
        $company = Company::factory()->create(['uses_material' => false, 'uses_stock' => false, 'uses_payroll' => false]);

        $this->assertSame(['Кооперант'], $this->labels($this->admin(), $company));
    }

    public function test_the_topbar_renders_the_quick_actions_search_and_initials(): void
    {
        $company = Company::factory()->create(['name' => 'ТЕСТ ДООЕЛ']);
        $admin = $this->admin();
        $admin->update(['name' => 'Тамара Мицевска']);

        $this->actingAs($admin)
            ->get(route('prodazba.dashboard', $company))
            ->assertOk()
            ->assertSee('aria-label="Нова"', false)
            ->assertSee('Излезна фактура')
            ->assertSee('Барај фактура, кооперант…')
            ->assertSee('ТМ');
    }

    private function searchable(): array
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->create(['company_id' => $company->id, 'name' => 'Алфа Трејд', 'tax_id' => '4030999123456']);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_number_formatted' => '2026-0042',
            'status' => 'confirmed',
        ]);

        return [$company, $partner, $invoice];
    }

    private function rowsOf(array $groups, string $title): array
    {
        foreach ($groups as $group) {
            if ($group['title'] === $title) {
                return array_column($group['rows'], 'primary');
            }
        }

        return [];
    }

    public function test_search_finds_an_invoice_by_number_and_a_partner_by_name_or_tax_id(): void
    {
        [$company, $partner, $invoice] = $this->searchable();
        $this->actingAs($this->admin());

        $byNumber = Livewire::test(GlobalSearch::class, ['company' => $company])->set('query', '0042')->instance()->groups();
        $this->assertSame(['2026-0042'], $this->rowsOf($byNumber, 'Излезни фактури'));

        $byName = Livewire::test(GlobalSearch::class, ['company' => $company])->set('query', 'Алфа')->instance()->groups();
        $this->assertSame(['Алфа Трејд'], $this->rowsOf($byName, 'Кооперанти'));
        // Фактурата се наоѓа и по името на кооперантот.
        $this->assertSame(['2026-0042'], $this->rowsOf($byName, 'Излезни фактури'));

        $byTaxId = Livewire::test(GlobalSearch::class, ['company' => $company])->set('query', '4030999')->instance()->groups();
        $this->assertSame(['Алфа Трејд'], $this->rowsOf($byTaxId, 'Кооперанти'));
    }

    public function test_search_needs_two_characters_and_treats_wildcards_as_text(): void
    {
        [$company] = $this->searchable();
        $this->actingAs($this->admin());

        $this->assertSame([], Livewire::test(GlobalSearch::class, ['company' => $company])->set('query', 'А')->instance()->groups());
        $this->assertSame([], Livewire::test(GlobalSearch::class, ['company' => $company])->set('query', '%%')->instance()->groups());
    }

    public function test_search_never_returns_another_companys_records(): void
    {
        [$company] = $this->searchable();
        $other = Company::factory()->create();
        Partner::factory()->create(['company_id' => $other->id, 'name' => 'Алфа Туѓа']);
        $this->actingAs($this->admin());

        $groups = Livewire::test(GlobalSearch::class, ['company' => $company])->set('query', 'Алфа')->instance()->groups();

        $this->assertSame(['Алфа Трејд'], $this->rowsOf($groups, 'Кооперанти'));
    }

    public function test_an_accountant_not_assigned_to_the_company_finds_nothing(): void
    {
        [$company] = $this->searchable();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $this->actingAs($accountant);

        $this->assertSame([], Livewire::test(GlobalSearch::class, ['company' => $company])->set('query', 'Алфа')->instance()->groups());
    }

    public function test_an_individual_search_has_no_purchase_group(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        Partner::factory()->create(['company_id' => $company->id, 'name' => 'Алфа Клиент']);
        $this->actingAs($this->admin());

        $groups = Livewire::test(GlobalSearch::class, ['company' => $company])->set('query', 'Алфа')->instance()->groups();

        $this->assertSame(['Кооперанти'], array_column($groups, 'title'));
    }

    public function test_without_a_company_search_finds_clients_the_user_can_see(): void
    {
        Company::factory()->create(['name' => 'Сигма Милк ДООЕЛ']);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $mine = Company::factory()->create(['name' => 'Сигма Мој ДООЕЛ']);
        $mine->accountants()->attach($accountant);

        $this->actingAs($accountant);

        $groups = Livewire::test(GlobalSearch::class)->set('query', 'Сигма')->instance()->groups();

        $this->assertSame(['Сигма Мој ДООЕЛ'], $this->rowsOf($groups, 'Клиенти'));
    }

    public function test_the_bell_counts_overdue_invoices_and_pending_incoming_documents(): void
    {
        Carbon::setTestNow('2026-06-15');
        $company = Company::factory()->create();
        WorkingYear::set($company, 2026);

        $invoice = SalesInvoice::factory()->for($company)->create([
            'invoice_date' => '2026-03-10', 'due_date' => '2026-04-10', 'status' => 'confirmed',
        ]);
        SalesInvoiceLine::factory()->for($invoice, 'salesInvoice')->create([
            'quantity' => '1.000', 'unit_price' => '50000.00', 'vat_rate' => '18.00',
        ]);
        IncomingEfakturaDocument::factory()->create(['company_id' => $company->id, 'decision' => null]);

        $this->actingAs($this->admin());

        $items = Livewire::test(Bell::class, ['company' => $company])->instance()->items();
        $byLabel = array_column($items, 'count', 'label');

        $this->assertSame(1, $byLabel['Задоцнети излезни фактури']);
        $this->assertSame(1, $byLabel['Нови влезни е-Фактури за прифаќање']);
        $this->assertArrayNotHasKey('Неуспешни е-Фактура испраќања', $byLabel);

        Carbon::setTestNow();
    }

    public function test_the_bell_shows_pending_743_forms_for_an_individual_only(): void
    {
        $individual = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        Form743::factory()->create(['company_id' => $individual->id]);
        $this->actingAs($this->admin());

        $items = Livewire::test(Bell::class, ['company' => $individual])->instance()->items();

        $this->assertSame(['Необработени 743 обрасци' => 1], array_column($items, 'count', 'label'));

        $legal = Company::factory()->create();
        $this->assertSame([], Livewire::test(Bell::class, ['company' => $legal])->instance()->items());
    }

    public function test_the_bell_is_empty_for_a_company_the_user_cannot_see(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        Form743::factory()->create(['company_id' => $company->id]);
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $this->actingAs($accountant);

        $this->assertSame([], Livewire::test(Bell::class, ['company' => $company])->instance()->items());
    }
}
