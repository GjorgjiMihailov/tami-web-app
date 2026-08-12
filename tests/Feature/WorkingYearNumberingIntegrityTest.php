<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The working year is a view filter. It must never reach a stored fiscal_year.
 *
 * If it could, an invoice dated January 2026 entered while the selector read
 * "2025" would be issued a number from the 2025 series — a silent data
 * integrity bug that would only surface at ДДВ filing time.
 */
class WorkingYearNumberingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_the_working_year_never_decides_an_invoices_fiscal_year(): void
    {
        // CompanyObserver auto-seeds the full official chart of accounts on
        // create, so every code SalesInvoiceService::confirm() looks up exists.
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        // The user is looking at 2025 in the sidebar...
        WorkingYear::set($company, 2025);

        // ...but writes an invoice dated January 2026.
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_date' => '2026-01-15',
            'status' => 'draft',
        ]);
        $invoice->lines()->create([
            'description' => 'Услуга',
            'quantity' => '1',
            'unit_price' => '1000.00',
            'vat_rate' => '0',
        ]);

        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $admin->id);

        $confirmed = $invoice->fresh();

        $this->assertSame(2026, (int) $confirmed->fiscal_year, 'The invoice date decides the fiscal year, not the selector.');
        $this->assertSame(1, (int) $confirmed->invoice_number, 'It must take number 1 of the 2026 series.');
    }

    public function test_the_working_year_never_decides_a_journal_entrys_fiscal_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        WorkingYear::set($company, 2025);

        $entry = JournalEntry::factory()->for($company)->create(['entry_date' => '2026-01-15']);

        $this->assertSame(2026, (int) $entry->fresh()->fiscal_year);
    }
}
