<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Една уплата (или исплата) што затвора повеќе фактури: ставката се дели на
 * по една ставка за фактура, најстарата прва. Збирот на износите останува ист.
 */
class BankStatementBookSplitInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        $this->partner = Partner::factory()->for($this->company)->create();
    }

    private function sale(string $price, string $date): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $this->partner->id, 'invoice_date' => $date, 'due_date' => $date]);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id);

        return $invoice->fresh();
    }

    private function purchase(string $price, string $date): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::factory()->for($this->company)->create(['partner_id' => $this->partner->id, 'invoice_date' => $date]);
        $expense = Account::where('company_id', $this->company->id)->where('code', '4620')->firstOrFail();
        $invoice->lines()->create(['account_id' => $expense->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => '0']);
        app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id);

        return $invoice->fresh();
    }

    private function screenWithLine(string $direction, string $amount, array $attrs = [])
    {
        $statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05']);

        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement])
            ->call('addLine')
            ->set('lines.0.direction', $direction)
            ->set('lines.0.amount', $amount)
            ->set('lines.0.partner_id', $this->partner->id);
    }

    private function sum($component): string
    {
        return collect($component->get('lines'))->reduce(fn ($carry, $line) => bcadd($carry, (string) $line['amount'], 2), '0.00');
    }

    public function test_one_receipt_closes_two_invoices_oldest_first_and_posts_both(): void
    {
        $older = $this->sale('300.00', '2026-01-10');
        $newer = $this->sale('200.00', '2026-02-10');

        // Означени обратно — редоследот го одредува староста, не кликот.
        $component = $this->screenWithLine('in', '500.00')
            ->call('splitAcrossInvoices', 0, [$newer->id, $older->id]);

        $lines = $component->get('lines');
        $this->assertCount(2, $lines);
        $this->assertSame($older->id, $lines[0]['invoice_id']);
        $this->assertSame('300.00', $lines[0]['amount']);
        $this->assertSame($newer->id, $lines[1]['invoice_id']);
        $this->assertSame('200.00', $lines[1]['amount']);
        $this->assertSame('500.00', $this->sum($component));

        foreach ($lines as $line) {
            $this->assertSame('invoice_payment', $line['kind']);
            $this->assertSame('in', $line['direction']);
            $this->assertSame($this->partner->id, $line['partner_id']);
            $this->assertSame('2026-03-05', $line['line_date']);
        }

        // Од почетокот до крај: зачувај, книжи — и двете фактури се платени.
        $component->set('openingBalance', '0.00')->set('closingBalance', '500.00')->call('post')->assertHasNoErrors();

        $this->assertSame('0.00', $older->fresh(['lines', 'payments'])->balanceDue());
        $this->assertSame('0.00', $newer->fresh(['lines', 'payments'])->balanceDue());
    }

    public function test_the_last_invoice_is_partly_closed_when_the_payment_runs_out(): void
    {
        $older = $this->sale('300.00', '2026-01-10');
        $newer = $this->sale('200.00', '2026-02-10');

        $component = $this->screenWithLine('in', '400.00')
            ->call('splitAcrossInvoices', 0, [$older->id, $newer->id]);

        $lines = $component->get('lines');
        $this->assertCount(2, $lines);
        $this->assertSame('300.00', $lines[0]['amount']);
        $this->assertSame('100.00', $lines[1]['amount']);
        $this->assertSame('400.00', $this->sum($component));
    }

    public function test_invoices_that_are_not_needed_are_left_alone_and_the_user_is_told(): void
    {
        $first = $this->sale('300.00', '2026-01-10');
        $second = $this->sale('200.00', '2026-02-10');
        $third = $this->sale('100.00', '2026-03-01');

        $component = $this->screenWithLine('in', '300.00')
            ->call('splitAcrossInvoices', 0, [$first->id, $second->id, $third->id]);

        $this->assertCount(1, $component->get('lines'));
        $this->assertSame($first->id, $component->get('lines.0.invoice_id'));
        $component->assertSee('Уплатата се потроши');
    }

    public function test_money_left_over_stays_as_a_line_without_an_invoice(): void
    {
        $older = $this->sale('300.00', '2026-01-10');
        $newer = $this->sale('200.00', '2026-02-10');

        $component = $this->screenWithLine('in', '700.00')
            ->call('splitAcrossInvoices', 0, [$older->id, $newer->id]);

        $lines = $component->get('lines');
        $this->assertCount(3, $lines);
        $this->assertSame('200.00', $lines[2]['amount']);
        $this->assertSame('account', $lines[2]['kind']);
        $this->assertNull($lines[2]['invoice_id']);
        $this->assertNull($lines[2]['account_id']);
        $this->assertSame($this->partner->id, $lines[2]['partner_id']);
        $this->assertSame('700.00', $this->sum($component));
    }

    public function test_a_payment_out_closes_several_purchase_invoices(): void
    {
        $older = $this->purchase('300.00', '2026-01-10');
        $newer = $this->purchase('200.00', '2026-02-10');

        $component = $this->screenWithLine('out', '500.00')
            ->call('splitAcrossInvoices', 0, [$older->id, $newer->id]);

        $lines = $component->get('lines');
        $this->assertCount(2, $lines);
        $this->assertSame([$older->id, $newer->id], [$lines[0]['invoice_id'], $lines[1]['invoice_id']]);
        $this->assertSame('out', $lines[1]['direction']);
        $this->assertSame('200.00', $lines[1]['debit']);
        $this->assertSame('', $lines[1]['credit']);
    }

    public function test_an_invoice_of_another_partner_cannot_be_picked(): void
    {
        $mine = $this->sale('300.00', '2026-01-10');
        $other = Partner::factory()->for($this->company)->create();
        $foreign = SalesInvoice::factory()->for($this->company)->create(['partner_id' => $other->id, 'invoice_date' => '2026-01-01', 'due_date' => '2026-01-01']);
        $foreign->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($foreign->fresh(), $this->admin->id);

        $component = $this->screenWithLine('in', '400.00')
            ->call('splitAcrossInvoices', 0, [$foreign->id, $mine->id]);

        // Само фактурата на партнерот на ставката; остатокот не се лепи на туѓа.
        $lines = $component->get('lines');
        $this->assertSame($mine->id, $lines[0]['invoice_id']);
        $this->assertNotContains($foreign->id, array_column($lines, 'invoice_id'));
        $this->assertSame('400.00', $this->sum($component));
    }

    public function test_nothing_valid_picked_changes_nothing_and_says_why(): void
    {
        $this->sale('300.00', '2026-01-10');

        $component = $this->screenWithLine('in', '300.00')
            ->call('splitAcrossInvoices', 0, [999999]);

        $this->assertCount(1, $component->get('lines'));
        $this->assertNull($component->get('lines.0.invoice_id'));
        $component->assertHasErrors(['lines.0.amount']);
    }

    public function test_it_needs_an_amount_first(): void
    {
        $invoice = $this->sale('300.00', '2026-01-10');

        $this->screenWithLine('in', '')
            ->call('splitAcrossInvoices', 0, [$invoice->id])
            ->assertHasErrors(['lines.0.amount']);
    }

    public function test_a_booked_statement_cannot_be_split(): void
    {
        $invoice = $this->sale('300.00', '2026-01-10');
        $statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05', 'status' => BankStatement::STATUS_BOOKED]);

        $component = Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement])
            ->call('splitAcrossInvoices', 0, [$invoice->id]);

        $this->assertCount(0, $component->get('lines'));
    }

    public function test_the_closing_cell_offers_the_multi_pick_only_with_two_or_more_open_invoices(): void
    {
        $this->sale('300.00', '2026-01-10');

        $this->screenWithLine('in', '300.00')->assertDontSee('Затвори повеќе фактури');

        $this->sale('200.00', '2026-02-10');

        $this->screenWithLine('in', '500.00')->assertSee('Затвори повеќе фактури');
    }

    public function test_account_codes_used_by_the_flow_exist(): void
    {
        // Заштита од тивко празен тест: пробата зависи од планот на конта на фирмата.
        $this->assertTrue(Account::where('company_id', $this->company->id)->where('code', '1000')->exists());
    }
}
