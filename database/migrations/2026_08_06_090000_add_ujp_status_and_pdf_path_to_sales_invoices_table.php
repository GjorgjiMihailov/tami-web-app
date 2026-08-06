<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('efaktura_ujp_status_code', 10)->nullable()->after('efaktura_error');
            $table->string('efaktura_ujp_status_name')->nullable()->after('efaktura_ujp_status_code');
            $table->string('efaktura_pdf_path')->nullable()->after('efaktura_ujp_status_name');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(['efaktura_ujp_status_code', 'efaktura_ujp_status_name', 'efaktura_pdf_path']);
        });
    }
};
