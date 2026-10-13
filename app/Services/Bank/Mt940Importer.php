<?php

namespace App\Services\Bank;

use App\Models\BankStatement;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Services\DocumentStorage;
use App\Support\Bank\LineKind;
use App\Support\Bank\Mt940\BankDirectory;
use App\Support\Bank\Mt940\Mt940Exception;
use App\Support\Bank\Mt940\Mt940Parser;
use App\Support\Bank\Mt940\Mt940Statement;
use App\Support\Bank\StatementSequence;
use App\Support\BankStatementKind;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Од прочитан MT940 создава извод во нацрт со ставки. Ништо не книжи и ништо
 * не погодува: ставките се „неразјаснети“, без партнер — препознавањето е
 * следна фаза.
 */
class Mt940Importer
{
    /**
     * Фајловите се читаат сите најпрво и се увезуваат по сметка и датум,
     * зашто проверката на ланецот гледа претходниот извод.
     *
     * @param  list<array{name: string, text: string, file?: ?TemporaryUploadedFile}>  $items
     * @return list<Mt940ImportOutcome>
     */
    public function importMany(Company $company, array $items): array
    {
        $outcomes = [];
        $ready = [];

        foreach ($items as $item) {
            try {
                $ready[] = ['item' => $item, 'statement' => Mt940Parser::parse($item['text'])];
            } catch (Mt940Exception $e) {
                $outcomes[] = Mt940ImportOutcome::rejected($item['name'], $e->getMessage());
            }
        }

        usort($ready, fn (array $a, array $b) => [$a['statement']->account, $a['statement']->date, $a['statement']->number]
            <=> [$b['statement']->account, $b['statement']->date, $b['statement']->number]);

        foreach ($ready as $entry) {
            $outcomes[] = $this->import($company, $entry['statement'], $entry['item']['name'], $entry['item']['file'] ?? null);
        }

        return $outcomes;
    }

    public function import(Company $company, Mt940Statement $parsed, string $name, ?TemporaryUploadedFile $file = null): Mt940ImportOutcome
    {
        $year = (int) substr($parsed->date, 0, 4);

        $belongsElsewhere = CompanyBankAccount::where('account_number', $parsed->account)->where('company_id', '!=', $company->id)->exists()
            || BankStatement::where('account', $parsed->account)->where('company_id', '!=', $company->id)->exists();

        if ($belongsElsewhere) {
            return Mt940ImportOutcome::rejected($name, "Сметката {$parsed->account} е сметка на друга фирма — изводот не е увезен.");
        }

        if (StatementSequence::numberTaken($company->id, $parsed->account, $parsed->number, $year)) {
            return Mt940ImportOutcome::rejected($name, "Извод бр. {$parsed->number} за сметка {$parsed->account} во {$year} веќе постои.");
        }

        $configured = CompanyBankAccount::where('company_id', $company->id)->where('account_number', $parsed->account)->first();
        $bank = $configured?->bank_name ?: (BankDirectory::name($parsed->bic) ?? $parsed->bic ?? 'Непозната банка');

        $statement = DB::transaction(function () use ($company, $parsed, $bank) {
            $statement = BankStatement::create([
                'company_id' => $company->id,
                'bank' => $bank,
                'account' => $parsed->account,
                'kind' => BankStatementKind::DENAR,
                'number' => $parsed->number,
                'statement_date' => $parsed->date,
                'uploaded_by' => auth()->id(),
                'opening_balance' => $parsed->opening,
                'closing_balance' => $parsed->closing,
            ]);

            foreach ($parsed->lines as $position => $line) {
                $statement->lines()->create([
                    'position' => $position + 1,
                    'line_date' => $line->date,
                    'direction' => $line->direction,
                    'amount' => $line->amount,
                    'description' => $line->description === '' ? null : $line->description,
                    'reference_number' => $line->referenceNumber,
                    'counterparty_account' => $line->counterpartyAccount,
                    'counterparty_name' => $line->counterpartyName,
                    'kind' => LineKind::UNCLEAR,
                ]);
            }

            return $statement;
        });

        if ($file !== null) {
            try {
                DocumentStorage::store($statement, $file, 'Bank Statement');
            } catch (\Throwable $e) {
                // Извод без фајл е ред што не води никаде.
                report($e);
                $statement->delete();

                return Mt940ImportOutcome::rejected($name, 'Фајлот не можеше да се зачува, изводот не е увезен.');
            }
        }

        $lineCount = count($parsed->lines);

        return Mt940ImportOutcome::created(
            $name,
            "Извод бр. {$parsed->number} од ".Format::date($statement->statement_date).": {$lineCount} ставки.",
            $statement->id,
            $lineCount,
            $this->warnings($statement, $parsed, $configured !== null),
        );
    }

    /** @return list<string> */
    private function warnings(BankStatement $statement, Mt940Statement $parsed, bool $accountKnown): array
    {
        $warnings = [];

        if (! $accountKnown) {
            $warnings[] = "Сметката {$parsed->account} не е во профилот на фирмата — ќе се додаде при првото книжење.";
        }

        if ($parsed->lines === []) {
            $warnings[] = 'Изводот нема ставки.';
        }

        $difference = $parsed->difference();

        if (bccomp($difference, '0', 2) !== 0) {
            $warnings[] = 'Почетна состојба + движење не е еднакво на крајната (разлика '.Format::money($difference, '').').';
        }

        $previous = BankStatement::where('company_id', $statement->company_id)
            ->where('account', $statement->account)
            ->where('number', $statement->number - 1)
            ->whereYear('statement_date', $statement->statement_date->year)
            ->first();

        if ($previous !== null && $previous->closing_balance !== null
            && bccomp((string) $previous->closing_balance, $parsed->opening, 2) !== 0) {
            $warnings[] = 'Почетната состојба ('.Format::money($parsed->opening, '').') не е еднаква на крајната од извод '
                .$previous->number.' ('.Format::money((string) $previous->closing_balance, '').').';
        }

        return $warnings;
    }
}
