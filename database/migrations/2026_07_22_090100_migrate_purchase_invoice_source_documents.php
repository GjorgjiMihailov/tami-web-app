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
        DB::table('documents')->where('documentable_type', 'purchase_invoice')->where('category', 'Invoice')->delete();
    }
};
