<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('payment_type_code', 3)->default('P12')->after('status');
            $table->string('efaktura_status', 20)->default('not_sent')->after('sent_at');
            $table->string('efaktura_doc_id')->nullable()->after('efaktura_status');
            $table->timestamp('efaktura_sent_at')->nullable()->after('efaktura_doc_id');
            $table->text('efaktura_error')->nullable()->after('efaktura_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_type_code', 'efaktura_status', 'efaktura_doc_id', 'efaktura_sent_at', 'efaktura_error']);
        });
    }
};
