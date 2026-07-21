<?php

namespace Tests\Unit;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoicePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_an_invoice_and_creator(): void
    {
        $invoice = PurchaseInvoice::factory()->create();
        $user = User::factory()->create();
        $payment = PurchaseInvoicePayment::factory()->for($invoice, 'purchaseInvoice')->create(['created_by' => $user->id]);

        $this->assertTrue($payment->purchaseInvoice->is($invoice));
        $this->assertTrue($payment->creator->is($user));
    }

    public function test_amount_is_cast_to_decimal(): void
    {
        $payment = PurchaseInvoicePayment::factory()->create(['amount' => '150.50']);

        $this->assertSame('150.50', (string) $payment->fresh()->amount);
    }
}
