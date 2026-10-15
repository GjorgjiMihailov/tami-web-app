<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Трајно ја брише фирмата и СИТЕ нејзини записи, вклучително и оние што базата
 * не дозволува да се избришат преку фирмата (сметка со ставки од книжење,
 * партнер со фактури, артикл со движења…).
 *
 * Врските се читаат од самата база: од редот на фирмата се оди надолу низ сите
 * надворешни клучеви и се собираат само редовите што зависат од неа. Врските со
 * „set null“ (на пример корисник со company_id) не се бришат, само се пуштаат.
 * Корисниците никогаш не се бришат.
 */
class PurgeCompany extends Command
{
    protected $signature = 'company:purge {id : Број на фирмата} {--apply : Избриши (без ова само брои)}';

    protected $description = 'Ја брише фирмата и сите нејзини записи (пробно извршување без --apply)';

    private const CHUNK = 500;

    public function handle(): int
    {
        $company = Company::find((int) $this->argument('id'));

        if ($company === null) {
            $this->error('Нема фирма со тој број.');

            return self::FAILURE;
        }

        [$rows, $leaves, $nulls] = $this->collect($company->id);

        $this->line("Фирма {$company->id}: {$company->name}");
        $this->line('Ќе се избришат:');

        foreach ($rows as $table => $ids) {
            $this->line(sprintf('  %-45s %d', $table, count($ids)));
        }

        foreach ($leaves as [$table, , $ids, $count]) {
            $this->line(sprintf('  %-45s %d', $table.' (врска)', $count));
        }

        if ($nulls !== []) {
            $this->line('Ќе се пуштат (врската се празни, редот останува):');

            foreach ($nulls as [$table, $column, $ids]) {
                $this->line(sprintf('  %-45s %d', $table.'.'.$column, count($ids)));
            }
        }

        if (isset($rows['documents'])) {
            $this->warn('Записите за документи се бришат, но самите фајлови во Google Drive остануваат.');
        }

        if (! $this->option('apply')) {
            $this->info('Пробно извршување — ништо не е избришано. Додај --apply за вистинско бришење.');

            return self::SUCCESS;
        }

        $typed = $this->ask('За да потврдиш, напиши го точното име на фирмата');

        if ($typed !== $company->name) {
            $this->error('Името не се совпаѓа. Ништо не е избришано.');

            return self::FAILURE;
        }

        // Проверките на странските клучеви се исклучуваат пред трансакцијата
        // (SQLite не дозволува промена во трансакција): редовите се веќе
        // собрани, па редоследот на бришење не е важен.
        Schema::withoutForeignKeyConstraints(function () use ($rows, $leaves, $nulls) {
            DB::transaction(function () use ($rows, $leaves, $nulls) {
                foreach ($nulls as [$table, $column, $ids]) {
                    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                        DB::table($table)->whereIn('id', $chunk)->update([$column => null]);
                    }
                }

                foreach ($leaves as [$table, $column, $parentIds]) {
                    foreach (array_chunk($parentIds, self::CHUNK) as $chunk) {
                        DB::table($table)->whereIn($column, $chunk)->delete();
                    }
                }

                foreach ($rows as $table => $ids) {
                    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                        DB::table($table)->whereIn('id', $chunk)->delete();
                    }
                }
            });
        });

        $this->info('Фирмата и сите нејзини записи се избришани.');

        return self::SUCCESS;
    }

    /**
     * Враќа [редови за бришење по табела, табели без id (по колона), редови за „set null“].
     *
     * @return array{0: array<string, list<int>>, 1: list<array>, 2: list<array>}
     */
    private function collect(int $companyId): array
    {
        $tables = array_column(Schema::getTables(), 'name');

        // родител => [[дете, колона во детето, колона во родителот, on_delete]]
        $children = [];

        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (count($fk['columns']) !== 1) {
                    $this->warn("Составен клуч во {$table} (".implode(',', $fk['columns']).') — не се следи.');

                    continue;
                }

                $children[$fk['foreign_table']][] = [$table, $fk['columns'][0], $fk['foreign_columns'][0], strtolower((string) $fk['on_delete'])];
            }
        }

        $rows = ['companies' => [$companyId]];
        $leaves = [];
        $nulls = [];
        $queue = [['companies', [$companyId]]];

        while ($queue !== []) {
            [$parent, $parentIds] = array_shift($queue);

            foreach ($children[$parent] ?? [] as [$child, $column, $parentColumn, $onDelete]) {
                // Врска на друга колона од родителот (не на id) — ги бараме
                // вредностите на таа колона.
                $values = $parentColumn === 'id'
                    ? $parentIds
                    : DB::table($parent)->whereIn('id', $parentIds)->pluck($parentColumn)->all();

                if ($values === []) {
                    continue;
                }

                $hasId = Schema::hasColumn($child, 'id');
                $found = [];

                foreach (array_chunk($values, self::CHUNK) as $chunk) {
                    $found = array_merge($found, $hasId
                        ? DB::table($child)->whereIn($column, $chunk)->pluck('id')->all()
                        : []);
                }

                if (! $hasId) {
                    // Табела без id (на пример врска корисник–фирма): бришење по колона.
                    $count = 0;

                    foreach (array_chunk($values, self::CHUNK) as $chunk) {
                        $count += DB::table($child)->whereIn($column, $chunk)->count();
                    }

                    if ($count > 0 && $onDelete !== 'set null') {
                        $leaves[] = [$child, $column, $values, $count];
                    }

                    continue;
                }

                if ($onDelete === 'set null') {
                    // Редовите што веќе се бришат не треба да се пуштаат.
                    $found = array_values(array_diff($found, $rows[$child] ?? []));

                    if ($found !== []) {
                        $nulls[] = [$child, $column, $found];
                    }

                    continue;
                }

                $new = array_values(array_diff($found, $rows[$child] ?? []));

                if ($new === []) {
                    continue;
                }

                $rows[$child] = array_merge($rows[$child] ?? [], $new);
                $queue[] = [$child, $new];
            }
        }

        // Ред што ќе се избрише не се пушта на null.
        $nulls = array_values(array_filter(array_map(function (array $n) use ($rows) {
            $n[2] = array_values(array_diff($n[2], $rows[$n[0]] ?? []));

            return $n[2] === [] ? null : $n;
        }, $nulls)));

        return [$rows, $leaves, $nulls];
    }
}
