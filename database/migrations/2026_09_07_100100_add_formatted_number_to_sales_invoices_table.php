<?php

use App\Models\SalesInvoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('invoice_number_formatted', 40)->nullable()->after('invoice_number');
        });

        // Пополнувањето оди преку Eloquent, не преку CONCAT: локално базата е
        // SQLite, во CI и продукција MySQL, а CONCAT не постои во SQLite.
        // Секоја постоечка потврдена фактура го добива точно својот сегашен
        // број, ГГГГ/Н — ниту една веќе испечатена фактура не си го менува.
        SalesInvoice::whereNotNull('invoice_number')
            ->each(function (SalesInvoice $invoice) {
                $invoice->updateQuietly([
                    'invoice_number_formatted' => $invoice->fiscal_year.'/'.$invoice->invoice_number,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('invoice_number_formatted');
        });
    }
};
