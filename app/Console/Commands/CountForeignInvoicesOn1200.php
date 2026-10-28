<?php

namespace App\Console\Commands;

use App\Models\SalesInvoice;
use App\Services\Posting\PostedInvoiceAccounts;
use Illuminate\Console\Command;

/**
 * Само брои: девизните фактури чие побарување стои на 1200 (или 120) наместо
 * на 1210/1211. Ништо не менува — така се гледа дали има книжења од пред
 * девизните конта што чекаат одлука.
 */
class CountForeignInvoicesOn1200 extends Command
{
    protected $signature = 'invoices:count-foreign-on-1200';

    protected $description = 'Ги брои потврдените девизни фактури книжени на 1200 (не се менува ништо)';

    public function handle(): int
    {
        $count = 0;

        SalesInvoice::query()
            ->where('status', 'confirmed')
            ->where('currency', '!=', 'MKD')
            ->whereNotNull('journal_entry_id')
            ->chunkById(200, function ($invoices) use (&$count) {
                foreach ($invoices as $invoice) {
                    $onOldAccount = collect(PostedInvoiceAccounts::receivables($invoice))
                        ->contains(fn (array $r) => in_array($r['account']->code, ['1200', '120'], true));

                    if ($onOldAccount) {
                        $count++;
                    }
                }
            });

        $this->line("Девизни фактури книжени на 1200: {$count}");

        return self::SUCCESS;
    }
}
