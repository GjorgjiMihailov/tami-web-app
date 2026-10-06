<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntryLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Еднократно: автоматските банкарски плаќања од фактури беа книжени на 100
 * (наслов), а од сега одат на 1000. Рачните налози не се допираат — само се
 * пријавуваат, за сметководителот да реши.
 */
class MoveBankPaymentsTo1000 extends Command
{
    protected $signature = 'bank:move-payments-to-1000 {--apply : Изврши ја промената (без ова само брои)}';

    protected $description = 'Ги преместува автоматските банкарски плаќања од конто 100 на 1000';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        foreach (Company::query()->cursor() as $company) {
            $heading = Account::where('company_id', $company->id)->where('code', '100')->first();
            $bank = Account::where('company_id', $company->id)->where('code', Account::BANK_CODE)->first();

            if ($heading === null || $bank === null) {
                continue;
            }

            $onHeading = JournalEntryLine::where('account_id', $heading->id);
            $automatic = (clone $onHeading)->whereHas('journalEntry', fn ($entry) => $entry
                ->where('description', 'like', 'Payment for%')
                ->whereHas('journalGroup', fn ($group) => $group->where('code', '99')));

            $moving = (clone $automatic)->count();
            $manual = (clone $onHeading)->count() - $moving;

            if ($moving === 0 && $manual === 0) {
                continue;
            }

            $this->line("{$company->name}: за преместување {$moving}, рачни редови на 100 (не се допираат) {$manual}");

            if ($apply && $moving > 0) {
                DB::transaction(fn () => $automatic->update(['account_id' => $bank->id]));
            }
        }

        $this->info($apply ? 'Готово.' : 'Пробно извршување — ништо не е променето. Додај --apply за вистинска промена.');

        return self::SUCCESS;
    }
}
