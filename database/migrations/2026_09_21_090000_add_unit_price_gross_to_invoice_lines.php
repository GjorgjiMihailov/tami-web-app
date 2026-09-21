<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цената со ДДВ што човекот ја впишал од хартија.
 *
 * Пополнета значи дека ставката е внесена со БРУТО цена и целата сметка се
 * гради наназад од неа: вкупно со ДДВ = количина × оваа цена, а основицата и
 * ДДВ-то се изведуваат од тоа. Така 6,00 × 6 дава точно 36,00 наместо 35,97,
 * колку што излегуваше кога основицата се градеше од заокружената нето цена.
 *
 * Празна значи стариот начин — основицата е количина × цена без ДДВ. Сите
 * постоечки ставки остануваат такви и ниедна пресметка не им се менува.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_invoice_lines', 'sales_invoice_lines'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->decimal('unit_price_gross', 15, 2)->nullable()->after('unit_price');
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_invoice_lines', 'sales_invoice_lines'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('unit_price_gross');
            });
        }
    }
};
