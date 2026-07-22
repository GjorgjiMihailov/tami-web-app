<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $invoices = DB::table('purchase_invoices')->whereNotNull('source_document_path')->get();

        foreach ($invoices as $invoice) {
            DB::table('documents')->insert([
                'company_id' => $invoice->company_id,
                'documentable_type' => 'purchase_invoice',
                'documentable_id' => $invoice->id,
                'category' => 'Invoice',
                'note' => null,
                'path' => $invoice->source_document_path,
                'original_filename' => basename($invoice->source_document_path),
                'mime_type' => null,
                'size' => 0,
                'uploaded_by' => $invoice->created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentional no-op: this migration performs a one-time, non-reversible
        // copy of historical source_document_path values into the documents
        // table. Reversing it by deleting rows matched on type+category alone
        // would risk deleting legitimate documents users later upload through
        // the normal DocumentManager UI with the same type/category — there is
        // no way to distinguish this migration's own rows from later ones by
        // type+category, so a safe reversal would require tracking inserted
        // IDs, which isn't worth the complexity for a one-time data-preservation
        // step. The paired schema-drop migration (2026_07_22_090200) is the one
        // that actually needs a working down() to restore the column, and it has one.
    }
};
