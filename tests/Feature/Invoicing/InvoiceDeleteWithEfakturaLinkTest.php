<?php

namespace Tests\Feature\Invoicing;

use App\Models\Account;
use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Livewire\Invoicing\PurchaseInvoiceShow;
use App\Models\User;
use App\Services\Invoicing\PurchaseInvoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Влезна фактура создадена со прифаќање на примена е-Фактура покажува кон неа
 * преку incoming_efaktura_documents.purchase_invoice_id. Таа врска нема правило
 * за бришење, па базата го одбива бришењето на фактурата (грешка наместо
 * бришење).
 */
class InvoiceDeleteWithEfakturaLinkTest extends TestCase
{
    use RefreshDatabase;

    private function acceptedInvoice(string $status): array
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'invoice_date' => '2026-03-01', 'status' => 'draft',
        ]);

        if ($status === 'confirmed') {
            $expense = Account::where('company_id', $company->id)->where('code', '4620')->firstOrFail();
            $invoice->lines()->create(['account_id' => $expense->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
            $invoice = app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
        }

        $document = IncomingEfakturaDocument::factory()->create([
            'company_id' => $company->id,
            'decision' => IncomingEfakturaDocument::DECISION_ACCEPTED,
            'decided_at' => now(),
            'purchase_invoice_id' => $invoice->id,
        ]);

        return [$invoice, $document];
    }

    public function test_a_draft_invoice_from_an_accepted_efaktura_can_be_deleted(): void
    {
        [$invoice, $document] = $this->acceptedInvoice('draft');

        app(PurchaseInvoiceService::class)->delete($invoice->fresh(), User::factory()->create()->id);

        $this->assertDatabaseMissing('purchase_invoices', ['id' => $invoice->id]);
        $this->assertNull($document->fresh()->purchase_invoice_id);
    }

    public function test_an_unexpected_database_error_on_delete_is_a_message_not_a_crash(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        [$invoice] = $this->acceptedInvoice('draft');

        $this->mock(PurchaseInvoiceService::class, function ($mock) {
            $mock->shouldReceive('delete')->andThrow(new QueryException('sqlite', 'delete from x', [], new \Exception('fk')));
        });

        Livewire::actingAs($admin)
            ->test(PurchaseInvoiceShow::class, ['company' => $invoice->company, 'purchaseInvoice' => $invoice])
            ->call('delete')
            ->assertHasErrors('delete');
    }

    public function test_a_confirmed_invoice_from_an_accepted_efaktura_can_be_deleted(): void
    {
        [$invoice, $document] = $this->acceptedInvoice('confirmed');

        app(PurchaseInvoiceService::class)->delete($invoice->fresh(), User::factory()->create()->id);

        $this->assertDatabaseMissing('purchase_invoices', ['id' => $invoice->id]);
        $this->assertNull($document->fresh()->purchase_invoice_id);
        $this->assertSame(IncomingEfakturaDocument::DECISION_ACCEPTED, $document->fresh()->decision);
    }
}
