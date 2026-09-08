<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Покрива database/migrations/2026_09_07_100100_add_formatted_number_to_sales_invoices_table.php
 * — единствениот код во оваа гранка што пишува врз ПОСТОЕЧКИ продукциски
 * редови. Го следи истиот пристап како JournalGroupBackfillMigrationTest:
 * враќање на шемата на пред-миграциската состојба, сеење редови, повторно
 * повикување на миграцијата, потврда на резултатот.
 */
class InvoiceNumberFormattedBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_backfill_migration_gives_confirmed_invoices_year_slash_number_leaves_drafts_null_and_ignores_a_companys_custom_format(): void
    {
        // Оваа миграција веќе се извршила еднаш при RefreshDatabase, па прво
        // ја враќаме шемата назад — истата постапка како
        // JournalGroupBackfillMigrationTest.
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('invoice_number_formatted');
        });

        $standardCompany = Company::factory()->create();

        // Фирма со НЕстандарден формат — доделен ПОСЛЕ фактурите се веќе
        // потврдени. Пополнувањето мора да го игнорира ова и да ја запише
        // старата ГГГГ/Н вредност, не новиот формат.
        $customCompany = Company::factory()->create([
            'invoice_number_prefix' => 'ФА-',
            'invoice_number_include_year' => true,
            'invoice_number_year_first' => false,
            'invoice_number_year_digits' => 2,
            'invoice_number_separator' => '-',
            'invoice_number_padding' => 5,
        ]);

        $confirmed = SalesInvoice::factory()
            ->for($standardCompany)
            ->for(Partner::factory()->for($standardCompany), 'partner')
            ->for(User::factory(), 'creator')
            ->create([
                'status' => 'confirmed',
                'fiscal_year' => 2026,
                'invoice_number' => 7,
            ]);

        $draft = SalesInvoice::factory()
            ->for($standardCompany)
            ->for(Partner::factory()->for($standardCompany), 'partner')
            ->for(User::factory(), 'creator')
            ->create([
                'status' => 'draft',
                'fiscal_year' => null,
                'invoice_number' => null,
            ]);

        $customFormatted = SalesInvoice::factory()
            ->for($customCompany)
            ->for(Partner::factory()->for($customCompany), 'partner')
            ->for(User::factory(), 'creator')
            ->create([
                'status' => 'confirmed',
                'fiscal_year' => 2026,
                'invoice_number' => 12,
            ]);

        // Истото повикување како во продукција: миграцијата ја додава
        // колоната и веднаш ги пополнува постојните редови во истиот up().
        (require database_path('migrations/2026_09_07_100100_add_formatted_number_to_sales_invoices_table.php'))->up();

        // Потврдена фактура добива точно ГГГГ/Н — истиот број што кодот го
        // печатеше пред гранката.
        $this->assertSame('2026/7', $confirmed->fresh()->invoice_number_formatted);

        // Нацрт без invoice_number останува непополнет.
        $this->assertNull($draft->fresh()->invoice_number_formatted);

        // Нестандардниот формат на фирмата НЕ смее да се примени наназад —
        // старата фактура ја задржува старата ГГГГ/Н вредност, не
        // „00012-26" или сличен нов формат.
        $this->assertSame('2026/12', $customFormatted->fresh()->invoice_number_formatted);
    }
}
