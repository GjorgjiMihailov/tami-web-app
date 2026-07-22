<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseInvoiceSourceDocumentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_copies_source_document_path_into_a_document_row(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('source_document_path')->nullable();
        });

        $invoiceId = DB::table('purchase_invoices')->insertGetId([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'supplier_invoice_number' => 'SUP-MIGRATE-1',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => 'draft',
            'source_document_path' => 'purchase-invoices/1/1/bill.pdf',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_07_22_090100_migrate_purchase_invoice_source_documents.php'))->up();

        $this->assertDatabaseHas('documents', [
            'documentable_type' => 'purchase_invoice',
            'documentable_id' => $invoiceId,
            'path' => 'purchase-invoices/1/1/bill.pdf',
            'category' => 'Invoice',
        ]);

        // Restore final schema state (column-less) for the rest of the suite.
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('source_document_path');
        });
    }
}
