<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\SalesInvoicePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForeignPaymentRateStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_payment_can_store_the_exchange_rate_of_the_day_and_a_denar_payment_has_none(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $userId = User::factory()->create()->id;

        $foreign = SalesInvoicePayment::create([
            'sales_invoice_id' => $invoice->id, 'amount' => '100.00', 'payment_date' => '2026-10-28',
            'payment_method' => 'bank', 'created_by' => $userId, 'exchange_rate' => '61.200000',
        ]);
        $denar = SalesInvoicePayment::create([
            'sales_invoice_id' => $invoice->id, 'amount' => '50.00', 'payment_date' => '2026-10-28',
            'payment_method' => 'bank', 'created_by' => $userId,
        ]);

        $this->assertSame(0, bccomp('61.2', (string) $foreign->fresh()->exchange_rate, 6));
        $this->assertNull($denar->fresh()->exchange_rate);
    }
}
