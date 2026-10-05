<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\OfficialChartOfAccounts;
use Illuminate\Console\Command;

class SyncOfficialChartOfAccounts extends Command
{
    protected $signature = 'accounts:sync-official';

    protected $description = 'Bring every company chart of accounts in line with docs/reference/official-chart-of-accounts.json (adds and updates, never deletes)';

    public function handle(): int
    {
        $count = 0;

        Company::query()->each(function (Company $company) use (&$count) {
            OfficialChartOfAccounts::syncForCompany($company);
            $count++;
        });

        $this->info("Контен план: усогласени {$count} фирми.");

        return self::SUCCESS;
    }
}
