<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoicePaperNumberFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
    }

    private function client(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');
        $this->actingAs($user);

        return $user;
    }

    private function admin(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('admin');
        $this->actingAs($user);

        return $user;
    }

    private function fill($component, Company $company, Partner $partner)
    {
        return $component
            ->set('partnerId', (string) $partner->id)
            ->set('invoiceDate', '2026-03-01')
            ->set('dueDate', '2026-03-15')
            ->set('lines', [[
                'item_id' => '',
                'description' => 'Услуга',
                'quantity' => '1',
                'unit_price' => '1000.00',
                'vat_rate' => '18.00',
                'vat_treatment' => 'standard',
            ]]);
    }

    public function test_a_paper_number_is_saved_on_the_draft(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->set('paperNumber', '2026/45')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sales_invoices', [
            'company_id' => $company->id,
            'invoice_number_formatted' => '2026/45',
            'status' => 'draft',
        ]);
    }

    public function test_an_empty_paper_number_leaves_the_column_null(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(SalesInvoice::where('company_id', $company->id)->first()->invoice_number_formatted);
    }

    public function test_a_duplicate_paper_number_in_the_same_year_is_refused(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_date' => '2026-05-01',
            'invoice_number_formatted' => '2026/45',
        ]);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->set('paperNumber', '2026/45')
            ->call('save')
            ->assertHasErrors('paperNumber');
    }

    public function test_the_same_paper_number_in_another_year_is_allowed(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'invoice_date' => '2025-05-01',
            'invoice_number_formatted' => '001',
        ]);

        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->set('paperNumber', '001')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_a_client_cannot_set_the_paper_number(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->client($company);

        // Полето е скриено во Blade, но `paperNumber` е јавно својство —
        // клиент може да го постави преку Livewire, а тој број потоа оди кон
        // УЈП како `docNumber`.
        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->set('paperNumber', '2026/45')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(SalesInvoice::where('company_id', $company->id)->first()->invoice_number_formatted);
    }

    public function test_a_client_does_not_erase_an_existing_paper_number(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->client($company);

        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-15',
            'invoice_number_formatted' => '2026/45',
        ]);

        // Да не смее да го постави не значи да го брише: нацртот што
        // сметководителот го внел од скен мора да го задржи својот број.
        $component = Livewire::test(SalesInvoiceForm::class, [
            'company' => $company,
            'salesInvoice' => $invoice,
        ]);

        $this->fill($component, $company, $partner)
            ->set('paperNumber', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026/45', $invoice->fresh()->invoice_number_formatted);
    }

    public function test_an_admin_keeps_the_paper_number_without_an_anthropic_key(): void
    {
        config(['services.anthropic.key' => null]);

        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        // Заклучувањето оди по ролја, не по „смее да чита скенови" — тоа бара
        // и клуч, па на сервер без клуч администратор што менува нацрт би му
        // го избришал бројот.
        $this->fill(Livewire::test(SalesInvoiceForm::class, ['company' => $company]), $company, $partner)
            ->set('paperNumber', '2026/45')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            '2026/45',
            SalesInvoice::where('company_id', $company->id)->first()->invoice_number_formatted
        );
    }

    public function test_editing_a_draft_keeps_its_paper_number_in_the_field(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $this->admin($company);

        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'invoice_number_formatted' => '2026/45',
        ]);

        Livewire::test(SalesInvoiceForm::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->assertSet('paperNumber', '2026/45');
    }
}
