<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Проверката оди прва и со јасна порака. Ако некоја фирма веќе носи два
        // исти испишани броја во иста година (можно ако форматот се менувал во
        // текот на годината), додавањето индекс би паднало со сурова SQL
        // грешка среде распоредување. Подобро е да падне тука, со список што
        // кажува што точно да се поправи.
        $duplicates = DB::table('sales_invoices')
            ->select('company_id', 'fiscal_year', 'invoice_number_formatted')
            ->whereNotNull('fiscal_year')
            ->whereNotNull('invoice_number_formatted')
            ->groupBy('company_id', 'fiscal_year', 'invoice_number_formatted')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $list = $duplicates
                ->map(fn ($row) => "фирма {$row->company_id}, {$row->fiscal_year}, број {$row->invoice_number_formatted}")
                ->implode('; ');

            throw new \RuntimeException("Постојат фактури со ист испишан број — поправи ги пред миграцијата: {$list}");
        }

        Schema::table('sales_invoices', function (Blueprint $table) {
            // Кратко име намерно: MySQL не прима име на индекс подолго од 64
            // знаци, а самосоздаденото од трите колони го надминува тоа.
            $table->unique(
                ['company_id', 'fiscal_year', 'invoice_number_formatted'],
                'sales_invoices_company_year_formatted_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropUnique('sales_invoices_company_year_formatted_unique');
        });
    }
};
