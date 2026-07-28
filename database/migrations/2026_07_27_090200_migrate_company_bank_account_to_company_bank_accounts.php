<?php

use App\Models\Company;
use App\Models\CompanyBankAccount;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Company::whereNotNull('bank_account')
            ->where('bank_account', '!=', '')
            ->each(function (Company $company) {
                CompanyBankAccount::create([
                    'company_id' => $company->id,
                    'bank_name' => null,
                    'account_number' => $company->bank_account,
                    'position' => 0,
                ]);
            });
    }

    public function down(): void
    {
        // Intentional no-op: reversing would mean picking one of up to 5
        // company_bank_accounts rows to collapse back into a single string
        // column, which is lossy and not a safe automatic operation. The
        // paired schema migration that drops `bank_account` is likewise a
        // documented no-op on down() — same precedent as the Phase 4a
        // purchase-invoice source-document migration.
    }
};
