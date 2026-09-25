<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('contact_salutation', 20)->nullable()->after('type');
            $table->string('contact_first_name', 100)->nullable()->after('contact_salutation');
            $table->string('contact_last_name', 100)->nullable()->after('contact_first_name');
            $table->string('mobile', 50)->nullable()->after('phone');
            // null = не е одредено; 0 = по приемот; инаку број на денови до доспевање.
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('invoice_language');
            $table->string('shipping_street_address')->nullable();
            $table->string('shipping_street_number', 50)->nullable();
            $table->string('shipping_postal_code', 20)->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_country')->nullable();
        });

        Schema::create('partner_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('salutation', 20)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_contacts');

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn([
                'contact_salutation', 'contact_first_name', 'contact_last_name', 'mobile', 'payment_terms_days',
                'shipping_street_address', 'shipping_street_number', 'shipping_postal_code', 'shipping_city', 'shipping_country',
            ]);
        });
    }
};
