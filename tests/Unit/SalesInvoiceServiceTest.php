<?php

namespace Tests\Unit;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoiceService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = app(SalesInvoiceService::class);
    }

    // NOTE: CompanyObserver already auto-seeds the full official chart (all
    // 428 accounts, including every code below) on Company::factory()->create().
    // firstOrCreate() keeps this helper's intent (guarantee these codes
    // exist) without violating the accounts(company_id, code) unique
    // constraint by double-inserting them.
    private function seedAccounts(Company $company): void
    {
        foreach ([
            ['code' => '120', 'name' => 'AR'],
            ['code' => '740', 'name' => 'Revenue'],
            ['code' => '230', 'name' => 'VAT Payable'],
            ['code' => '660', 'name' => 'Inventory Asset'],
            ['code' => '701', 'name' => 'COGS'],
            ['code' => '100', 'name' => 'Bank'],
            ['code' => '102', 'name' => 'Cash'],
        ] as $account) {
            Account::firstOrCreate(
                ['company_id' => $company->id, 'code' => $account['code']],
                $account
            );
        }
    }

    public function test_confirming_a_service_only_invoice_posts_ar_revenue_and_vat(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame(2026, $confirmed->fiscal_year);
        $this->assertSame(1, $confirmed->invoice_number);
        $this->assertNotNull($confirmed->journal_entry_id);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(3, $entry->lines);

        $ar = $entry->lines->firstWhere('account.code', '120');
        $revenue = $entry->lines->firstWhere('account.code', '740');
        $vat = $entry->lines->firstWhere('account.code', '230');

        $this->assertSame('1180.00', (string) $ar->debit);
        $this->assertSame('1000.00', (string) $revenue->credit);
        $this->assertSame('180.00', (string) $vat->credit);
    }

    public function test_confirming_an_item_line_issues_stock_and_posts_cogs(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['vat_rate' => '18.00']);
        $user = User::factory()->create();

        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $user->id);

        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => '2026-03-01',
        ]);
        $line = $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '4', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $this->assertSame('6.000', (string) \App\Models\StockLevel::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first()->quantity_on_hand);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(5, $entry->lines);

        $cogs = $entry->lines->firstWhere('account.code', '701');
        $inventoryAsset = $entry->lines->firstWhere('account.code', '660');

        // 4 units issued at the receipted cost of 50.00 each = 200.00 COGS
        $this->assertSame('200.00', (string) $cogs->debit);
        $this->assertSame('200.00', (string) $inventoryAsset->credit);

        $this->assertSame($line->fresh()->stock_movement_id, \App\Models\StockMovement::where('item_id', $item->id)->where('type', 'issue')->first()->id);
    }

    public function test_confirming_skips_vat_when_company_is_not_vat_registered(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $confirmed = $this->service->confirm($invoice->fresh(), $user->id);

        $entry = $confirmed->journalEntry()->with('lines.account')->first();
        $this->assertCount(2, $entry->lines);
        $this->assertNull($entry->lines->firstWhere('account.code', '230'));
    }

    public function test_invoice_numbers_are_sequential_per_company_per_fiscal_year(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $user = User::factory()->create();

        foreach ([1, 2] as $n) {
            $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-0'.$n]);
            $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);
            $confirmed = $this->service->confirm($invoice->fresh(), $user->id);
            $this->assertSame($n, $confirmed->invoice_number);
        }
    }

    public function test_confirming_requires_at_least_one_line(): void
    {
        $invoice = SalesInvoice::factory()->create();
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice, $user->id);
    }

    public function test_confirming_an_already_confirmed_invoice_throws(): void
    {
        $invoice = SalesInvoice::factory()->create(['status' => 'confirmed']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice, $user->id);
    }

    public function test_confirming_an_item_line_without_a_warehouse_throws(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['warehouse_id' => null]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0']);
        $user = User::factory()->create();

        $this->expectException(InvalidInvoiceStateException::class);

        $this->service->confirm($invoice->fresh(), $user->id);
    }
}
