<?php

namespace Tests\Feature;

use App\Livewire\DocumentManager;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_a_client_can_upload_a_document_to_their_own_sales_invoice(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(DocumentManager::class, ['documentable' => $invoice])
            ->set('newFile', UploadedFile::fake()->create('delivery-note.pdf', 20))
            ->set('newCategory', 'Contract')
            ->call('upload')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('documents', [
            'documentable_type' => 'sales_invoice',
            'documentable_id' => $invoice->id,
        ]);
    }

    public function test_the_show_page_renders_the_document_manager(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('sales-invoices.show', [$company, $invoice]))
            ->assertOk()
            ->assertSeeLivewire('document-manager');
    }
}
