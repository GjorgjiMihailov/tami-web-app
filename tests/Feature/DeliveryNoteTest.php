<?php

namespace Tests\Feature;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Partner;
use App\Models\ProformaInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\DeliveryNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeliveryNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    // ---- Модел ----

    public function test_a_delivery_note_belongs_to_its_company_and_its_deliverable(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);

        $note = DeliveryNote::factory()->create([
            'company_id' => $company->id,
            'deliverable_type' => ProformaInvoice::class,
            'deliverable_id' => $proforma->id,
            'delivery_note_number_formatted' => 'ИСП-2026/1',
        ]);

        $note->refresh();
        $this->assertTrue($note->company->is($company));
        $this->assertTrue($note->deliverable->is($proforma));
        $this->assertSame('ИСП-2026/1', $note->delivery_note_number_formatted);
    }

    public function test_the_company_defaults_its_delivery_note_prefix(): void
    {
        $company = Company::factory()->create();

        $this->assertSame('ИСП-', $company->delivery_note_number_prefix);
    }

    // ---- Нумерирање ----

    public function test_it_numbers_with_its_own_prefix_and_series_across_both_source_types(): void
    {
        $company = Company::factory()->create(['delivery_note_number_prefix' => 'ISP/']);
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice = SalesInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed', 'fiscal_year' => now()->year, 'invoice_number' => 1]);

        $service = app(DeliveryNoteService::class);
        $first = $service->createOrGetFor($proforma);
        $second = $service->createOrGetFor($invoice);

        $this->assertSame('ISP/'.now()->year.'/1', $first->delivery_note_number_formatted);
        $this->assertSame('ISP/'.now()->year.'/2', $second->delivery_note_number_formatted);
    }

    public function test_each_company_has_its_own_series(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $proformaA = ProformaInvoice::factory()->create(['company_id' => $a->id, 'partner_id' => Partner::factory()->for($a)->create()->id, 'status' => 'confirmed']);
        $proformaB = ProformaInvoice::factory()->create(['company_id' => $b->id, 'partner_id' => Partner::factory()->for($b)->create()->id, 'status' => 'confirmed']);
        $service = app(DeliveryNoteService::class);

        $service->createOrGetFor($proformaA);
        $first = $service->createOrGetFor($proformaB);

        $this->assertSame(1, $first->delivery_note_number);
    }

    public function test_calling_it_twice_for_the_same_source_returns_the_same_delivery_note(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $proforma = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'confirmed']);
        $service = app(DeliveryNoteService::class);

        $first = $service->createOrGetFor($proforma);
        $second = $service->createOrGetFor($proforma);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DeliveryNote::count());
    }

    public function test_a_draft_proforma_or_invoice_cannot_get_a_delivery_note(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $draft = ProformaInvoice::factory()->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'status' => 'draft']);

        $this->expectException(InvalidInvoiceStateException::class);
        app(DeliveryNoteService::class)->createOrGetFor($draft);
    }
}
