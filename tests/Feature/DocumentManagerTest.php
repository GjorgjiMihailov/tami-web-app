<?php

namespace Tests\Feature;

use App\Livewire\DocumentManager;
use App\Models\Company;
use App\Models\Document;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentManagerTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_uploading_a_document_attaches_it_to_the_purchase_invoice(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(DocumentManager::class, ['documentable' => $invoice])
            ->set('newFile', UploadedFile::fake()->create('bill.pdf', 50))
            ->set('newCategory', 'Invoice')
            ->set('newNote', 'Supplier scan')
            ->call('upload')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('documents', [
            'company_id' => $company->id,
            'documentable_type' => 'purchase_invoice',
            'documentable_id' => $invoice->id,
            'category' => 'Invoice',
            'note' => 'Supplier scan',
        ]);
        $document = Document::where('documentable_id', $invoice->id)->firstOrFail();
        Storage::disk('google')->assertExists($document->path);
    }

    public function test_a_failed_upload_does_not_leave_a_placeholder_document_row(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $failingDisk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $failingDisk->shouldReceive('put')
            ->andThrow(new \RuntimeException('simulated storage failure'));
        Storage::partialMock()->shouldReceive('disk')->with('google')->andReturn($failingDisk);

        $this->expectException(\RuntimeException::class);

        try {
            Livewire::test(DocumentManager::class, ['documentable' => $invoice])
                ->set('newFile', UploadedFile::fake()->create('bill.pdf', 50))
                ->set('newCategory', 'Invoice')
                ->call('upload');
        } finally {
            $this->assertSame(0, Document::withTrashed()->count());
        }
    }

    public function test_a_client_cannot_view_another_companys_purchase_invoice_documents(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($otherCompany)->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(DocumentManager::class, ['documentable' => $invoice])
            ->assertForbidden();
    }

    public function test_deleting_a_document_soft_deletes_it_and_hides_it_from_the_list(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(DocumentManager::class, ['documentable' => $invoice])
            ->call('delete', $document->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
        $this->assertSame(0, $invoice->documents()->count());
    }

    public function test_downloading_a_document_requires_view_permission_on_its_parent(): void
    {
        Storage::fake('google');
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($otherCompany)->create();
        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $otherCompany->id, 'path' => 'documents/test/bill.pdf']);
        Storage::disk('google')->put($document->path, 'fake-pdf-content');
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get(route('documents.download', [$otherCompany, $document]))->assertForbidden();
    }

    public function test_downloading_a_document_succeeds_for_an_authorized_user(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'path' => 'documents/test/bill.pdf']);
        Storage::disk('google')->put($document->path, 'fake-pdf-content');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('documents.download', [$company, $document]));

        $response->assertOk();
    }
}
