<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Support\Facades\File;

class OfficialChartOfAccounts
{
    public static function seedForCompany(Company $company): void
    {
        $path = base_path('docs/reference/official-chart-of-accounts.json');

        $accounts = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($accounts as $account) {
            Account::create([
                'company_id' => $company->id,
                'code' => $account['code'],
                'name' => $account['name'],
                'parent_code' => null,
                'is_analytical' => false,
                'is_active' => true,
            ]);
        }
    }
}
