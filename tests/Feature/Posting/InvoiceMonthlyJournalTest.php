<?php

namespace Tests\Feature\Posting;

use App\Livewire\Accounting\JournalEntryForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Accounting\LedgerCardQuery;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\InvoiceJournal;
use App\Services\Posting\InvoiceJournalConsolidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceMonthlyJournalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['is_vat_registered' => true]);
        $this->user = User::factory()->create();
    }

    private function sales(string $date, string $price = '1000.00', ?Partner $partner = null): SalesInvoice
    {
        $partner ??= Partner::factory()->for($this->company)->create();
        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $partner->id, 'invoice_date' => $date]);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => '18.00']);

        return $invoice;
    }

    private function purchase(string $date, string $number = '77'): PurchaseInvoice
    {
        $partner = Partner::factory()->for($this->company)->create(['name' => 'Добавувач']);
        $invoice = PurchaseInvoice::factory()->for($this->company)->create([
            'partner_id' => $partner->id, 'supplier_invoice_number' => $number, 'invoice_date' => $date,
        ]);
        $invoice->lines()->create([
            'account_id' => Account::where('company_id', $this->company->id)->where('code', '4620')->value('id'),
            'description' => 'Консалтинг', 'quantity' => '1', 'unit_price' => '500.00', 'vat_rate' => '18.00',
        ]);

        return $invoice;
    }

    private function confirmSales(SalesInvoice $invoice): SalesInvoice
    {
        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
    }

    private function confirmPurchase(PurchaseInvoice $invoice): PurchaseInvoice
    {
        return app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
    }

    public function test_sales_invoices_of_one_month_share_one_entry_in_group_30_numbered_by_month(): void
    {
        $a = $this->confirmSales($this->sales('2026-01-05'));
        $b = $this->confirmSales($this->sales('2026-01-28'));

        $this->assertSame($a->journal_entry_id, $b->journal_entry_id);

        $entry = JournalEntry::with('journalGroup', 'lines')->findOrFail($a->journal_entry_id);
        $this->assertSame('30-0001', $entry->displayNumber());
        $this->assertSame('2026-01-31', $entry->entry_date->toDateString());
        $this->assertCount(6, $entry->lines);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame(1, JournalEntry::where('company_id', $this->company->id)->count());
    }

    public function test_a_later_month_gets_its_own_entry_with_the_month_number(): void
    {
        $jan = $this->confirmSales($this->sales('2026-01-10'));
        $mar = $this->confirmSales($this->sales('2026-03-02'));

        $this->assertNotSame($jan->journal_entry_id, $mar->journal_entry_id);
        $this->assertSame('30-0003', JournalEntry::with('journalGroup')->find($mar->journal_entry_id)->displayNumber());
    }

    public function test_a_new_year_starts_again_from_the_month_number(): void
    {
        $this->confirmSales($this->sales('2026-02-10'));
        $next = $this->confirmSales($this->sales('2027-02-03'));

        $entry = JournalEntry::with('journalGroup')->find($next->journal_entry_id);

        $this->assertSame('30-0002', $entry->displayNumber());
        $this->assertSame(2027, $entry->fiscal_year);
    }

    public function test_purchase_invoices_go_to_group_20_the_same_way(): void
    {
        $a = $this->confirmPurchase($this->purchase('2026-02-04', '11'));
        $b = $this->confirmPurchase($this->purchase('2026-02-20', '12'));

        $this->assertSame($a->journal_entry_id, $b->journal_entry_id);
        $this->assertSame('20-0002', JournalEntry::with('journalGroup')->find($a->journal_entry_id)->displayNumber());
    }

    public function test_the_issue_date_picks_the_month_not_the_confirmation_date(): void
    {
        Carbon::setTestNow('2026-06-15');
        $invoice = $this->confirmSales($this->sales('2026-04-30'));

        $entry = JournalEntry::with('journalGroup')->find($invoice->journal_entry_id);

        $this->assertSame('30-0004', $entry->displayNumber());
        $this->assertTrue($entry->lines->every(fn ($l) => $l->line_date->toDateString() === '2026-04-30'));
    }

    public function test_every_line_remembers_its_invoice(): void
    {
        $a = $this->confirmSales($this->sales('2026-01-05'));
        $b = $this->confirmSales($this->sales('2026-01-06'));

        $journal = app(InvoiceJournal::class);

        $this->assertCount(3, $journal->linesOf($a));
        $this->assertCount(3, $journal->linesOf($b));
        $this->assertTrue($journal->linesOf($a)->every(fn ($l) => $l->source_id === $a->id && $l->source_type === InvoiceJournal::SALES));
    }

    public function test_deleting_one_invoice_keeps_the_others_lines_and_the_last_removes_the_entry(): void
    {
        $a = $this->confirmSales($this->sales('2026-01-05'));
        $b = $this->confirmSales($this->sales('2026-01-06'));
        $entryId = $a->journal_entry_id;

        app(SalesInvoiceService::class)->delete($a->fresh(), $this->user->id);

        $entry = JournalEntry::with('lines')->findOrFail($entryId);
        $this->assertCount(3, $entry->lines);
        $this->assertTrue($entry->lines->every(fn ($l) => $l->source_id === $b->id));
        $this->assertTrue($entry->isBalanced());

        app(SalesInvoiceService::class)->delete($b->fresh(), $this->user->id);

        $this->assertNull(JournalEntry::find($entryId));
    }

    public function test_changing_a_number_rewrites_only_that_invoices_lines(): void
    {
        $a = $this->confirmSales($this->sales('2026-01-05'));
        $b = $this->confirmSales($this->sales('2026-01-06'));
        $oldA = $a->invoice_number_formatted;
        $oldB = $b->invoice_number_formatted;

        app(SalesInvoiceService::class)->changeNumber($a->fresh(), 'НОВ-77', $this->user->id);

        $journal = app(InvoiceJournal::class);
        $this->assertTrue($journal->linesOf($a->fresh())->every(fn ($l) => str_contains($l->description, 'НОВ-77') && ! str_contains($l->description, $oldA.' ')));
        $this->assertTrue($journal->linesOf($b->fresh())->every(fn ($l) => str_contains($l->description, $oldB)));
    }

    public function test_the_ledger_card_places_each_line_on_its_invoice_date(): void
    {
        $this->confirmSales($this->sales('2026-01-05', '1000.00'));
        $this->confirmSales($this->sales('2026-01-28', '2000.00'));

        $rows = LedgerCardQuery::run($this->company, [
            'account_id' => Account::where('company_id', $this->company->id)->where('code', '1200')->value('id'),
            'from' => Carbon::parse('2026-01-01'),
            'to' => Carbon::parse('2026-01-15'),
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(1180.0, $rows[0]['debit']);
        $this->assertSame('2026-01-05', $rows[0]['date']->toDateString());
    }

    public function test_a_manually_taken_month_number_falls_back_to_the_next_free_one(): void
    {
        $group = JournalGroup::create(['company_id' => $this->company->id, 'code' => '30', 'name' => 'Рачна', 'sort_order' => 30]);
        $manual = JournalEntry::create([
            'company_id' => $this->company->id, 'journal_group_id' => $group->id,
            'entry_date' => '2026-01-10', 'description' => 'Рачен налог', 'created_by' => $this->user->id,
        ]);
        $this->assertSame(1, $manual->entry_number);

        $invoice = $this->confirmSales($this->sales('2026-01-12'));

        $entry = JournalEntry::find($invoice->journal_entry_id);
        $this->assertNotSame($manual->id, $entry->id);
        $this->assertSame(2, $entry->entry_number);
    }

    public function test_the_monthly_entry_cannot_be_overwritten_from_the_entry_form(): void
    {
        Role::findOrCreate('admin');
        $this->user->assignRole('admin');
        $this->actingAs($this->user);
        $invoice = $this->confirmSales($this->sales('2026-01-05'));
        $entry = JournalEntry::findOrFail($invoice->journal_entry_id);

        Livewire::test(JournalEntryForm::class, ['company' => $this->company, 'journalEntry' => $entry])
            ->call('save')
            ->assertHasErrors('lines');

        $this->assertCount(3, $entry->fresh()->lines);
    }

    public function test_the_consolidator_gathers_old_one_per_invoice_entries_without_changing_amounts(): void
    {
        $legacy = JournalGroup::create(['company_id' => $this->company->id, 'code' => '99', 'name' => 'Автоматски (фактури)', 'sort_order' => 99]);
        $partner = Partner::factory()->for($this->company)->create();
        $receivable = Account::where('company_id', $this->company->id)->where('code', '1200')->value('id');
        $income = Account::where('company_id', $this->company->id)->where('code', '74000')->value('id');

        $invoices = [];
        foreach (['2026-01-05' => '100.00', '2026-01-20' => '200.00', '2026-02-02' => '300.00'] as $date => $amount) {
            $invoice = SalesInvoice::factory()->for($this->company)->create([
                'partner_id' => $partner->id, 'invoice_date' => $date, 'status' => 'confirmed',
            ]);
            $entry = JournalEntry::create([
                'company_id' => $this->company->id, 'journal_group_id' => $legacy->id,
                'entry_date' => $date, 'description' => "Sales Invoice {$invoice->id}", 'created_by' => $this->user->id,
            ]);
            $entry->lines()->create(['account_id' => $receivable, 'partner_id' => $partner->id, 'description' => 'x', 'line_date' => null, 'debit' => $amount, 'credit' => 0]);
            $entry->lines()->create(['account_id' => $income, 'partner_id' => $partner->id, 'description' => 'x', 'line_date' => $date, 'debit' => 0, 'credit' => $amount]);
            $invoice->update(['journal_entry_id' => $entry->id]);
            $invoices[] = $invoice;
        }

        // Налог создаден рачно во група 99 што НЕ е на фактура останува недопрен.
        $stray = JournalEntry::create([
            'company_id' => $this->company->id, 'journal_group_id' => $legacy->id,
            'entry_date' => '2026-01-09', 'description' => 'Плаќање', 'created_by' => $this->user->id,
        ]);
        $stray->lines()->create(['account_id' => $receivable, 'description' => 'p', 'line_date' => '2026-01-09', 'debit' => 0, 'credit' => 50]);
        $stray->lines()->create(['account_id' => $income, 'description' => 'p', 'line_date' => '2026-01-09', 'debit' => 50, 'credit' => 0]);

        $before = \App\Models\JournalEntryLine::selectRaw('SUM(debit) d, SUM(credit) c, COUNT(*) n')->first();

        $result = app(InvoiceJournalConsolidator::class)->run();

        $this->assertSame(3, $result['sales']);

        $after = \App\Models\JournalEntryLine::selectRaw('SUM(debit) d, SUM(credit) c, COUNT(*) n')->first();
        $this->assertEquals($before->d, $after->d);
        $this->assertEquals($before->c, $after->c);
        $this->assertEquals($before->n, $after->n);

        $jan = JournalEntry::with('journalGroup', 'lines')->find($invoices[0]->fresh()->journal_entry_id);
        $this->assertSame('30-0001', $jan->displayNumber());
        $this->assertSame($jan->id, $invoices[1]->fresh()->journal_entry_id);
        $this->assertCount(4, $jan->lines);
        $this->assertTrue($jan->lines->every(fn ($l) => $l->line_date !== null));

        $feb = JournalEntry::with('journalGroup')->find($invoices[2]->fresh()->journal_entry_id);
        $this->assertSame('30-0002', $feb->displayNumber());

        // Празните стари налози се избришани; налогот што не е на фактура останал.
        $this->assertSame(1, JournalEntry::where('journal_group_id', $legacy->id)->count());
        $this->assertNotNull(JournalEntry::find($stray->id));

        // Втор пат: ништо не се менува.
        $again = app(InvoiceJournalConsolidator::class)->run();
        $this->assertSame(0, $again['sales']);
    }
}
