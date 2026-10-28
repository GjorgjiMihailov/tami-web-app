<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\JournalEntryLine;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceShowForeignPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function confirmed(bool $foreign): SalesInvoice
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(array_merge(
            ['partner_id' => $partner->id, 'invoice_date' => '2026-10-01'],
            $foreign ? ['currency' => 'EUR', 'exchange_rate' => '61.500000'] : []
        ));
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '0.00', 'vat_treatment' => $foreign ? 'export' : 'standard']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->admin->id);
    }

    private function show(SalesInvoice $invoice)
    {
        return Livewire::actingAs($this->admin)->test(SalesInvoiceShow::class, ['company' => $invoice->company, 'salesInvoice' => $invoice]);
    }

    public function test_the_rate_field_is_shown_only_for_a_foreign_invoice(): void
    {
        $this->show($this->confirmed(true))->assertSee('Курс на денот');
        $this->show($this->confirmed(false))->assertDontSee('Курс на денот');
    }

    public function test_the_cash_method_is_not_offered_for_a_foreign_invoice(): void
    {
        $this->show($this->confirmed(true))->assertDontSeeHtml('value="cash"');
        $this->show($this->confirmed(false))->assertSeeHtml('value="cash"');
    }

    public function test_the_nbrm_button_fills_the_rate_for_the_payment_date(): void
    {
        ExchangeRate::create(['rate_date' => '2026-10-20', 'currency_code' => 'EUR', 'rate' => '61.4955']);

        $this->show($this->confirmed(true))
            ->set('paymentDate', '2026-10-20')
            ->call('fetchPaymentRate')
            ->assertHasNoErrors()
            ->assertSet('paymentRate', '61.4955');
    }

    public function test_a_failing_nbrm_call_keeps_the_field_typeable(): void
    {
        Http::fake(['www.nbrm.mk/*' => Http::response('', 500)]);

        $this->show($this->confirmed(true))
            ->set('paymentDate', '2026-10-20')
            ->call('fetchPaymentRate')
            ->assertHasErrors('paymentRate');
    }

    public function test_recording_a_foreign_payment_through_the_screen_posts_it(): void
    {
        $invoice = $this->confirmed(true);

        $this->show($invoice)
            ->set('paymentAmount', '1000.00')
            ->set('paymentDate', '2026-10-20')
            ->set('paymentRate', '61.2')
            ->call('recordPayment')
            ->assertHasNoErrors();

        $line = JournalEntryLine::whereHas('account', fn ($q) => $q->where('code', '1030')->where('company_id', $invoice->company_id))->first();
        $this->assertNotNull($line);
        $this->assertSame('61200.00', (string) $line->debit);
    }

    public function test_a_foreign_payment_without_a_rate_shows_a_field_error(): void
    {
        $this->show($this->confirmed(true))
            ->set('paymentAmount', '1000.00')
            ->set('paymentDate', '2026-10-20')
            ->call('recordPayment')
            ->assertHasErrors('paymentRate');
    }
}
