<?php

namespace App\Observers;

use App\Models\Company;
use App\Services\OfficialChartOfAccounts;

class CompanyObserver
{
    public function created(Company $company): void
    {
        OfficialChartOfAccounts::seedForCompany($company);
    }
}
