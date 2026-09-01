<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\Item;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardTilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function client(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('client');

        return $user;
    }

    /**
     * Потврдена излезна фактура со една ставка (quantity x 50000.00 + 18% ДДВ).
     * По подразбирање 1.000 x 50000.00 = 59.000 вкупно — истиот помошник како
     * во Task 7, проширен со по избор количина за тестови на кои им треба
     * бројка различна од 59.000.
     */
    private function invoice(
        Company $company,
        string $date,
        string $status = 'confirmed',
        ?string $dueDate = null,
        string $quantity = '1.000',
    ): SalesInvoice {
        $invoice = SalesInvoice::factory()->for($company)->create([
            'invoice_date' => $date,
            'due_date' => $dueDate ?? $date,
            'status' => $status,
        ]);

        SalesInvoiceLine::factory()->for($invoice, 'salesInvoice')->create([
            'quantity' => $quantity,
            'unit_price' => '50000.00',
            'vat_rate' => '18.00',
        ]);

        return $invoice->fresh();
    }

    /**
     * Потврдена влезна фактура со една ставка (quantity x 50000.00 + 18% ДДВ).
     * По подразбирање 1.000 x 50000.00 = 59.000 вкупно — истиот помошник како
     * во Task 7, проширен со по избор количина.
     */
    private function purchaseInvoice(
        Company $company,
        string $date,
        string $status = 'confirmed',
        ?string $dueDate = null,
        string $quantity = '1.000',
    ): PurchaseInvoice {
        $invoice = PurchaseInvoice::factory()->for($company)->create([
            'invoice_date' => $date,
            'due_date' => $dueDate ?? $date,
            'status' => $status,
        ]);

        PurchaseInvoiceLine::factory()->for($invoice, 'purchaseInvoice')->create([
            'quantity' => $quantity,
            'unit_price' => '50000.00',
            'vat_rate' => '18.00',
        ]);

        return $invoice->fresh();
    }

    public function test_a_legal_profile_shows_the_money_tiles(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);
        $this->invoice($company, '2026-03-10');

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Приход')
            ->assertSee('59.000')
            ->assertSee('Ненаплатено');
    }

    public function test_the_dashboard_shows_costs_and_the_difference(): void
    {
        Carbon::setTestNow('2026-06-15');

        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);
        // Приход 88.500 (1.500 x 50000 + 18%), трошок 59.000, разлика 29.500 —
        // три различни бројки за да не се совпаѓаат случајно.
        $this->invoice($company, '2026-03-10', quantity: '1.500');
        $this->purchaseInvoice($company, '2026-04-10');

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Приход')
            ->assertSee('88.500')
            ->assertSee('Трошоци')
            ->assertSee('59.000')
            ->assertSee('Разлика')
            ->assertSee('29.500');

        Carbon::setTestNow();
    }

    public function test_the_dashboard_shows_the_overdue_portion_of_receivables_and_payables(): void
    {
        Carbon::setTestNow('2026-06-15');

        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);
        // Доспеана — рокот е минат.
        $this->invoice($company, '2026-03-10', 'confirmed', '2026-04-10');
        // Уште не доспеана.
        $this->invoice($company, '2026-03-10', 'confirmed', '2026-12-31');

        $this->purchaseInvoice($company, '2026-03-10', 'confirmed', '2026-04-10');
        $this->purchaseInvoice($company, '2026-03-10', 'confirmed', '2026-12-31');

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            // Вкупно ненаплатено 118.000, од тоа 59.000 доспеано.
            ->assertSee('118.000')
            ->assertSee('59.000')
            ->assertSeeInOrder(['Ненаплатено', '118.000', '59.000', 'Обврски', '118.000', '59.000']);

        Carbon::setTestNow();
    }

    public function test_the_dashboard_shows_failed_and_sent_efaktura_counts(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);
        $this->invoice($company, '2026-03-10')->update(['efaktura_status' => 'failed']);
        $this->invoice($company, '2026-04-10')->update(['efaktura_status' => 'sent']);
        $this->invoice($company, '2026-05-10')->update(['efaktura_status' => 'sent']);

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('е-Фактура')
            ->assertSee('2 испратени')
            ->assertSee('1 со грешка');
    }

    public function test_the_dashboard_shows_vat_due_for_the_current_period(): void
    {
        Carbon::setTestNow('2026-06-15');

        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);
        // Излезна фактура во тековниот месец (јуни): 50.000 основа x 18% = 9.000 ДДВ.
        $this->invoice($company, '2026-06-05');

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('ДДВ')
            ->assertSee('9.000');

        Carbon::setTestNow();
    }

    public function test_the_vat_tile_is_hidden_from_a_client_role(): void
    {
        Carbon::setTestNow('2026-06-15');

        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);
        $this->invoice($company, '2026-06-05');

        Livewire::actingAs($this->client($company))
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertDontSee('ДДВ за тековниот период');

        Carbon::setTestNow();
    }

    public function test_the_dashboard_shows_stock_value(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);
        $item = Item::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $user = $this->admin();
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $user->id);

        Livewire::actingAs($user)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertSee('Вредност на залихата')
            ->assertSee('500');
    }

    public function test_tiles_link_to_the_screens_that_explain_them(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);
        $this->invoice($company, '2026-03-10')->update(['efaktura_status' => 'failed']);

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertSeeHtml(route('sales-invoices.index', $company))
            ->assertSeeHtml(route('purchase-invoices.index', $company))
            ->assertSeeHtml(route('inventory.reports.stock-on-hand', $company))
            ->assertSeeHtml(route('reports.ddv04', $company));
    }

    public function test_an_individual_profile_does_not_show_the_legal_tiles(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyDashboard::class, ['company' => $company])
            ->assertDontSee('Приход за работната година')
            ->assertDontSee('Вредност на залихата');
    }
}
