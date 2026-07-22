<?php

namespace Tests\Feature;

use App\Livewire\DocumentIndex;
use App\Models\Company;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_lists_documents_across_entity_types_for_the_company(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $entry = JournalEntry::factory()->for($company)->create();
        Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'category' => 'Invoice', 'original_filename' => 'bill.pdf']);
        Document::factory()->for($entry, 'documentable')->create(['company_id' => $company->id, 'category' => 'Bank Statement', 'original_filename' => 'statement.pdf']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(DocumentIndex::class, ['company' => $company])
            ->assertSee('bill.pdf')
            ->assertSee('statement.pdf');
    }

    public function test_it_excludes_documents_from_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $otherInvoice = PurchaseInvoice::factory()->for($otherCompany)->create();
        Document::factory()->for($otherInvoice, 'documentable')->create(['company_id' => $otherCompany->id, 'original_filename' => 'other-company-file.pdf']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(DocumentIndex::class, ['company' => $company])
            ->assertDontSee('other-company-file.pdf');
    }

    public function test_it_filters_by_category(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'category' => 'Invoice', 'original_filename' => 'invoice-doc.pdf']);
        Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'category' => 'Contract', 'original_filename' => 'contract-doc.pdf']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(DocumentIndex::class, ['company' => $company])
            ->set('categoryFilter', 'Invoice')
            ->assertSee('invoice-doc.pdf')
            ->assertDontSee('contract-doc.pdf');
    }

    public function test_a_client_cannot_view_another_companys_documents_index(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(DocumentIndex::class, ['company' => $otherCompany])
            ->assertForbidden();
    }
}
