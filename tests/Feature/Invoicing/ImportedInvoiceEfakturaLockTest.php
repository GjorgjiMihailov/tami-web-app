<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportedInvoiceEfakturaLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function adminWithToken(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C'])->save();
        $admin->assignRole('admin');

        return $admin->fresh();
    }

    /** 1000.00 + 18% ДДВ = 1180.00 */
    private function invoice(bool $imported, string $status = 'confirmed', ?string $paperTotal = null): array
    {
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'street_address' => 'Мајка Тереза', 'street_number' => '12',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $partner = Partner::factory()->for($company)->create([
            'street_address' => 'Партизанска', 'street_number' => '5',
            'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => $status,
            'fiscal_year' => $status === 'confirmed' ? 2026 : null,
            'invoice_number_formatted' => '00001-26',
            'invoice_date' => '2026-03-01',
            'is_imported' => $imported,
            'imported_total' => $paperTotal,
        ]);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);

        return [$company, $invoice->fresh(['lines'])];
    }

    public function test_signing_input_is_refused_for_an_imported_invoice(): void
    {
        [$company, $invoice] = $this->invoice(imported: true);

        $this->actingAs($this->adminWithToken())->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertStatus(422);
    }

    public function test_send_is_refused_for_an_imported_invoice_and_names_the_reason(): void
    {
        [$company, $invoice] = $this->invoice(imported: true);

        $response = $this->actingAs($this->adminWithToken())->postJson(
            route('sales-invoices.efaktura.send', [$company, $invoice]),
            ['token' => 'x', 'signature' => 'y']
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('увезена', mb_strtolower($response->json('message')));
        $this->assertNotSame('sent', $invoice->fresh()->efaktura_status);
    }

    public function test_an_ordinary_invoice_is_not_refused_for_that_reason(): void
    {
        [$company, $invoice] = $this->invoice(imported: false);

        $this->actingAs($this->adminWithToken())->postJson(
            route('sales-invoices.efaktura.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->assertOk();
    }

    public function test_the_show_screen_hides_the_send_block_and_says_why(): void
    {
        [$company, $invoice] = $this->invoice(imported: true);

        Livewire::actingAs($this->adminWithToken())
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('Потпиши и испрати до УЈП')
            ->assertSee('не се испраќа до УЈП');
    }

    public function test_the_show_screen_warns_when_the_totals_differ_from_the_paper(): void
    {
        [$company, $invoice] = $this->invoice(imported: true, paperTotal: '2360.00');

        Livewire::actingAs($this->adminWithToken())
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('2360.00')
            ->assertSee('не е ист со износот од хартијата');
    }

    public function test_the_show_screen_is_quiet_when_the_totals_match_the_paper(): void
    {
        [$company, $invoice] = $this->invoice(imported: true, paperTotal: '1180.00');

        Livewire::actingAs($this->adminWithToken())
            ->test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertDontSee('не е ист со износот од хартијата');
    }

    public function test_the_edit_form_warns_when_the_totals_differ_from_the_paper(): void
    {
        [$company, $invoice] = $this->invoice(imported: true, status: 'draft', paperTotal: '2360.00');

        Livewire::actingAs($this->adminWithToken())
            ->test(SalesInvoiceForm::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSee('не е ист со износот од хартијата');
    }

    public function test_confirming_an_imported_draft_keeps_the_paper_number_and_spares_the_counter(): void
    {
        [$company, $invoice] = $this->invoice(imported: true, status: 'draft');
        $user = User::factory()->create();

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice, $user->id);

        $this->assertSame('00001-26', $confirmed->formattedNumber());
        $this->assertNull($confirmed->invoice_number);
        $this->assertTrue($confirmed->is_imported);
    }
}
