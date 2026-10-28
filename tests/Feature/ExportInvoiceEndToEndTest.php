<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\JournalEntryLine;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Reports\Ddv04Query;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExportInvoiceEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** Извозна фактура на ДДВ-обврзник (правно лице): стока $goods и услуга $services во EUR, курс 61,5. */
    private function export(string $goods, string $services, bool $confirm = true): SalesInvoice
    {
        $company = Company::factory()->create([
            'type' => 'legal', 'is_vat_registered' => true,
            'street_address' => 'Мајка Тереза', 'street_number' => '12', 'postal_code' => '1000', 'city' => 'Скопје',
        ]);
        $partner = Partner::factory()->for($company)->create([
            'street_address' => 'Hauptstrasse', 'street_number' => '5', 'postal_code' => '10115', 'city' => 'Berlin',
        ]);
        $this->user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'invoice_date' => '2026-10-28', 'is_export' => true,
            'currency' => 'EUR', 'exchange_rate' => '61.500000', 'status' => $confirm ? 'draft' : 'confirmed',
        ]);

        if (bccomp($goods, '0', 2) > 0) {
            $warehouse = Warehouse::factory()->for($company)->create();
            $item = Item::factory()->for($company)->create(['type' => 'product']);
            app(StockMovementService::class)->receipt($item, $warehouse, '10', '1.00', '2026-09-01', $this->user->id);
            $invoice->update(['warehouse_id' => $warehouse->id]);
            $invoice->lines()->create(['item_id' => $item->id, 'description' => 'Стока', 'quantity' => '1', 'unit_price' => $goods, 'vat_rate' => '0.00', 'vat_treatment' => 'export']);
        }

        if (bccomp($services, '0', 2) > 0) {
            $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => $services, 'vat_rate' => '0.00', 'vat_treatment' => 'export']);
        }

        return $confirm ? app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id) : $invoice;
    }

    private function codes(SalesInvoice $invoice): array
    {
        return $invoice->journalEntry->lines()->with('account')->get()
            ->map(fn ($l) => $l->account->code.($l->debit > 0 ? ' D ' : ' C ').($l->debit > 0 ? $l->debit : $l->credit))->all();
    }

    public function test_an_export_invoice_of_a_legal_entity_posts_to_1211_and_7423_with_no_vat(): void
    {
        $codes = $this->codes($this->export('0', '500.00'));

        $this->assertEqualsCanonicalizing(['1211 D 30750.00', '7423 C 30750.00'], $codes);
    }

    public function test_an_export_invoice_with_goods_and_services_posts_to_1210_1211_7421_7423(): void
    {
        $codes = $this->codes($this->export('600.00', '400.00'));

        $this->assertEqualsCanonicalizing(
            ['1210 D 36900.00', '1211 D 24600.00', '7421 C 36900.00', '7423 C 24600.00', '7010 D 1.00', '6600 C 1.00'],
            $codes
        );
    }

    public function test_an_export_invoice_cannot_be_sent_to_ujp(): void
    {
        $invoice = $this->export('0', '500.00');
        Role::findOrCreate('accountant');
        $accountant = User::factory()->create(['efaktura_eujp_id' => 'EUJP-ACC', 'efaktura_token_serial_number' => 'AAA111']);
        $accountant->assignRole('accountant');
        $invoice->company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->postJson(route('sales-invoices.efaktura.signing-input', [$invoice->company, $invoice]), ['certificateBase64' => 'x'])
            ->assertStatus(422);
    }

    public function test_both_goods_and_service_export_lines_land_in_ddv04_field_07_in_denars(): void
    {
        $invoice = $this->export('600.00', '400.00', false);

        $result = Ddv04Query::run($invoice->company, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        // 1.000 EUR × 61,50 = 61.500 денари — не 1.000.
        $this->assertSame('61500.00', $result['07']);
        foreach (['01', '02', '03', '04', '05', '06', '08', '09', '20'] as $field) {
            $this->assertSame('0.00', $result[$field], "Поле {$field}");
        }
    }

    public function test_a_payment_of_an_export_invoice_posts_to_1030_and_closes_1211(): void
    {
        $invoice = $this->export('0', '500.00');

        app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), '500.00', '2026-11-05', 'bank', $this->user->id, '61.200000');

        $balance = fn (string $code) => JournalEntryLine::whereHas('account', fn ($q) => $q->where('company_id', $invoice->company_id)->where('code', $code))
            ->get()->reduce(fn (string $c, $l) => bcsub(bcadd($c, (string) $l->debit, 2), (string) $l->credit, 2), '0.00');

        $this->assertSame('0.00', $balance('1211'));
        $this->assertSame('30600.00', $balance('1030'));
        $this->assertSame('150.00', $balance('4750'));
    }
}
