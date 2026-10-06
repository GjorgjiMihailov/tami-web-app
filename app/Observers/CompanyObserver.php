<?php

namespace App\Observers;

use App\Models\Company;
use App\Services\OfficialChartOfAccounts;
use App\Services\Posting\PostingSchemeSets;

class CompanyObserver
{
    public function created(Company $company): void
    {
        OfficialChartOfAccounts::seedForCompany($company);

        // Предлогот на сметководителот што ја создава фирмата (ако има). Без
        // најавен корисник (команда, тест) — ништо; шемите се лено стандардни.
        $creator = auth()->user();

        if ($creator !== null && $creator->hasAnyRole(['admin', 'accountant'])) {
            PostingSchemeSets::seedCompany($company, $creator);
        }
    }
}
