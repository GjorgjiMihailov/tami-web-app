<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceShow;
use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceShowEfakturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    /** Токенот е личен — на човекот што потпишува, не на фирмата. */
    private function giveToken(User $user): User
    {
        $user->forceFill(['efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C'])->save();

        return $user->fresh();
    }

    private function invoiceFromIncoming(Company $company, Partner $partner, ?string $pdfPath): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        IncomingEfakturaDocument::factory()->create([
            'company_id' => $company->id,
            'purchase_invoice_id' => $invoice->id,
            'decision' => IncomingEfakturaDocument::DECISION_ACCEPTED,
            'efaktura_pdf_path' => $pdfPath,
        ]);

        return $invoice->fresh();
    }

    public function test_a_plain_purchase_invoice_with_no_incoming_document_shows_no_efaktura_button(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $admin = $this->giveToken(User::factory()->create());
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $invoice])
            ->assertDontSee('Преземи е-Фактура');
    }

    public function test_an_already_downloaded_pdf_shows_a_direct_download_link(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = $this->invoiceFromIncoming($company, $partner, 'efaktura-pdfs/incoming/1/1.pdf');
        $admin = $this->giveToken(User::factory()->create());
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $invoice])
            ->assertSeeHtml(route('incoming-efaktura.pdf.download', [$company, $invoice->fresh()->incomingEfakturaDocument]))
            ->assertSee('Преземи е-Фактура');
    }

    public function test_an_undownloaded_pdf_shows_the_fetch_button_only_with_a_token(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = $this->invoiceFromIncoming($company, $partner, null);
        $admin = $this->giveToken(User::factory()->create());
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $invoice])
            ->assertSee('Преземи е-Фактура')
            ->assertDontSeeHtml(route('incoming-efaktura.pdf.download', [$company, $invoice->fresh()->incomingEfakturaDocument]));

        $noToken = User::factory()->create();
        $noToken->assignRole('admin');

        Livewire::actingAs($noToken)
            ->test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $invoice])
            ->assertDontSee('Преземи е-Фактура');
    }
}
