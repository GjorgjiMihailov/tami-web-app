<?php

namespace App\Services\Bank;

use App\Models\BankStatement;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Services\DocumentStorage;
use App\Support\Bank\AccountNumber;
use App\Support\Bank\LineKind;
use App\Support\Bank\MatchConfidence;
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
    public function __construct(private readonly BankCounterpartyRecognizer $recognizer) {}

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
            try {
                $outcomes[] = $this->import($company, $entry['statement'], $entry['item']['name'], $entry['item']['file'] ?? null);
            } catch (\Throwable $e) {
                // Една неочекувана грешка не смее да ја прекине серијата: фајловите
                // пред неа веќе се прокнижени во базата, а корисникот мора да види
                // што се случило со секој.
                report($e);
                $outcomes[] = Mt940ImportOutcome::rejected($entry['item']['name'], 'Неочекувана грешка при увоз — изводот не е увезен.');
            }
        }

        return $outcomes;
    }

    public function import(Company $company, Mt940Statement $parsed, string $name, ?TemporaryUploadedFile $file = null): Mt940ImportOutcome
    {
        $year = (int) substr($parsed->date, 0, 4);

        $holder = $this->holderElsewhere($company, $parsed->account);

        if ($holder !== null) {
            return Mt940ImportOutcome::rejected($name, $this->foreignAccountMessage($parsed->account, $holder));
        }

        // Сметката е запишана на фирмата можеби на друг начин (со цртички, како
        // IBAN): дупликатот и низата се проценуваат по сите облици, а новиот
        // извод го добива обликот што фирмата веќе го користи.
        $profileAccounts = CompanyBankAccount::where('company_id', $company->id)->get();
        $configured = $profileAccounts->first(fn (CompanyBankAccount $a) => AccountNumber::same($a->account_number, $parsed->account));
        $spellings = BankStatement::where('company_id', $company->id)->distinct()->pluck('account')
            ->filter(fn (string $account) => AccountNumber::same($account, $parsed->account));
        $account = $configured?->account_number ?? $spellings->first() ?? $parsed->account;

        $allSpellings = $spellings->push($account, $parsed->account)->unique()->values()->all();

        if (StatementSequence::numberTaken($company->id, $allSpellings, $parsed->number, $year)) {
            return Mt940ImportOutcome::rejected($name, "Извод бр. {$parsed->number} за сметка {$parsed->account} во {$year} веќе постои.");
        }

        $bank = $configured?->bank_name ?: (BankDirectory::name($parsed->bic) ?? $parsed->bic ?? 'Непозната банка');

        $statement = DB::transaction(function () use ($company, $parsed, $bank, $account) {
            $statement = BankStatement::create([
                'company_id' => $company->id,
                'bank' => $bank,
                'account' => $account,
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
                    'match_confidence' => MatchConfidence::UNCLEAR,
                    'match_reason' => 'Другата страна не е препознаена.',
                    // Запомнето од претходно книжење по сметка на другата страна.
                    ...$this->recognizer->attributesFor($company, $line),
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

    /**
     * Каде е запишана истата сметка (во кој било облик) кај друга фирма: во
     * профилот или во некој извод. Празно = слободна.
     *
     * @return array{company_id: int, via: string}|null
     */
    private function holderElsewhere(Company $company, string $account): ?array
    {
        $profile = CompanyBankAccount::where('company_id', '!=', $company->id)->get(['company_id', 'account_number'])
            ->first(fn (CompanyBankAccount $row) => AccountNumber::same($row->account_number, $account));

        if ($profile !== null) {
            return ['company_id' => $profile->company_id, 'via' => 'во профилот'];
        }

        $statement = BankStatement::where('company_id', '!=', $company->id)
            ->select('company_id', 'account')->distinct()->get()
            ->first(fn (BankStatement $row) => AccountNumber::same($row->account, $account));

        if ($statement === null) {
            return null;
        }

        $number = BankStatement::where('company_id', $statement->company_id)
            ->where('account', $statement->account)->orderBy('statement_date')->value('number');

        return ['company_id' => $statement->company_id, 'via' => "во извод бр. {$number}"];
    }

    /**
     * Името на другата фирма се покажува само ако корисникот ја гледа таа фирма;
     * инаку сметководител би ги испитувал сметките на туѓи клиенти.
     *
     * @param  array{company_id: int, via: string}  $holder
     */
    private function foreignAccountMessage(string $account, array $holder): string
    {
        $visible = auth()->user()?->visibleCompanies()->whereKey($holder['company_id'])->exists() ?? false;

        if (! $visible) {
            return "Сметката {$account} е запишана кај друга фирма што не ви е доделена — изводот не е увезен.";
        }

        $name = Company::find($holder['company_id'])?->name ?? '—';

        return "Сметката {$account} е запишана кај друга фирма: {$name} ({$holder['via']}). "
            .'Ако е грешка, избришете го тој извод (или тргнете ја сметката од профилот на таа фирма) и увезете пак.';
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
