<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceFormPaymentTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_defaults_to_p12_and_can_be_changed(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(SalesInvoiceForm::class, ['company' => $company]);
        $this->assertSame('P12', $component->get('paymentTypeCode'));

        $component
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('paymentTypeCode', 'P10')
            ->set('lines.0.description', 'Test line')
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_price', '100')
            ->call('save');

        $invoice = SalesInvoice::first();
        $this->assertSame('P10', $invoice->payment_type_code);
    }
}
