<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyBankAccountMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_copies_an_existing_bank_account_value_into_a_bank_account_row(): void
    {
        $company = Company::factory()->create();

        Schema::table('companies', function (Blueprint $table) {
            $table->string('bank_account')->nullable();
        });

        DB::table('companies')->where('id', $company->id)->update([
            'bank_account' => 'MK07300701104789126',
        ]);

        (require database_path('migrations/2026_07_27_090200_migrate_company_bank_account_to_company_bank_accounts.php'))->up();

        $this->assertDatabaseHas('company_bank_accounts', [
            'company_id' => $company->id,
            'bank_name' => null,
            'account_number' => 'MK07300701104789126',
            'position' => 0,
        ]);

        // Restore final schema state (column-less) for the rest of the suite.
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('bank_account');
        });
    }

    public function test_a_company_with_no_bank_account_value_gets_no_row(): void
    {
        $company = Company::factory()->create();

        Schema::table('companies', function (Blueprint $table) {
            $table->string('bank_account')->nullable();
        });

        (require database_path('migrations/2026_07_27_090200_migrate_company_bank_account_to_company_bank_accounts.php'))->up();

        $this->assertDatabaseCount('company_bank_accounts', 0);

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('bank_account');
        });
    }
}
