<?php

namespace App\Console\Commands;

use App\Support\Bank\Mt940\Mt940FolderReport;
use Illuminate\Console\Command;

/**
 * Ги чита сите MT940 фајлови од папка и пријавува што не е во ред. Не пристапува
 * до базата — вистинските фајлови се проверуваат на машината, без да влезат во
 * репото или во апликацијата.
 */
class CheckMt940Folder extends Command
{
    protected $signature = 'bank:check-mt940 {folder : Папка со MT940 фајлови (.txt, .sta, .mt940)}';

    protected $description = 'Проверува папка со MT940 фајлови: дупки, прекини на ланец и разлики, без запишување';

    public function handle(): int
    {
        $folder = (string) $this->argument('folder');

        if (! is_dir($folder)) {
            $this->error("Папката не постои: {$folder}");

            return self::FAILURE;
        }

        $files = [];

        foreach (scandir($folder) ?: [] as $name) {
            if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['txt', 'sta', 'mt940'], true)) {
                $files[$name] = (string) file_get_contents($folder.DIRECTORY_SEPARATOR.$name);
            }
        }

        if ($files === []) {
            $this->error('Нема MT940 фајлови во папката.');

            return self::FAILURE;
        }

        $report = Mt940FolderReport::analyse($files);
        $problems = $report['refused'] !== [] || $report['duplicates'] !== [];

        $this->line("Фајлови: {$report['files']}, изводи: {$report['statements']}, ставки: {$report['lines']}");

        foreach ($report['accounts'] as $key => $account) {
            $this->line(sprintf(
                'Сметка %s: изводи %d–%d (%d); дупки: %s; прекини: %s; разлики: %s',
                $key,
                $account['first'],
                $account['last'],
                $account['count'],
                $account['gaps'] === [] ? 'нема' : implode('; ', $account['gaps']),
                $account['chain_breaks'] === [] ? 'нема' : implode('; ', $account['chain_breaks']),
                $account['mismatches'] === [] ? 'нема' : implode('; ', $account['mismatches']),
            ));

            $problems = $problems || $account['gaps'] !== [] || $account['chain_breaks'] !== [] || $account['mismatches'] !== [];
        }

        foreach ($report['refused'] as $refused) {
            $this->warn("Одбиен {$refused['file']}: {$refused['reason']}");
        }

        foreach ($report['duplicates'] as $duplicate) {
            $this->warn("Дупликат: {$duplicate}");
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
