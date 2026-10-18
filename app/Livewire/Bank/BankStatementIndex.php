<?php

namespace App\Livewire\Bank;

use App\Models\BankStatement;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Bank\Mt940Importer;
use App\Services\DocumentStorage;
use App\Support\Bank\BankLedgerTotals;
use App\Support\Bank\StatementSequence;
use App\Support\BankStatementKind;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Изводите на една фирма: качување и список со проверка на низата.
 *
 * Банката, сметката и видот остануваат пополнети по качувањето — изводите се
 * внесуваат еден по еден за цел месец, па по првиот остануваат само бројот,
 * датумот и фајлот.
 */
#[Layout('layouts.app')]
class BankStatementIndex extends Component
{
    use WithFileUploads;

    public Company $company;

    public string $bank = '';

    public string $account = '';

    public string $kind = BankStatementKind::DENAR->value;

    public string $number = '';

    public string $statementDate = '';

    public $newFile = null;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $mt940Files = [];

    /** @var array<int, array<string, mixed>> */
    public array $importResults = [];

    /** Организатор: основно се гледаат само непрокнижените. 'open' | 'booked' | 'all'. */
    public string $statusFilter = 'open';

    /** '' = сите, инаку вредност на BankStatementKind. */
    public string $kindFilter = '';

    /** '' = сите сметки. */
    public string $accountFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    /** 'number' | 'date' | 'uploaded'. */
    public string $sortBy = 'number';

    /** 'asc' | 'desc'. */
    public string $sortDirection = 'asc';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;

        // Најчестата сметка е онаа што фирмата веќе ја има внесено во профилот.
        $configured = $company->bankAccounts()->first();
        $this->bank = $configured?->bank_name ?? '';
        $this->account = $configured?->account_number ?? '';
    }

    public function saveStatement(): void
    {
        Gate::authorize('view', $this->company);

        $data = $this->validate([
            'bank' => 'required|string|max:255',
            'account' => 'required|string|max:34',
            'kind' => ['required', Rule::enum(BankStatementKind::class)],
            'number' => 'required|integer|min:1',
            'statementDate' => 'required|date|before_or_equal:today',
            'newFile' => 'required|file|max:25600',
        ], [
            'bank.required' => 'Внесете ја банката.',
            'account.required' => 'Внесете ја сметката.',
            'number.required' => 'Внесете го бројот на изводот.',
            'number.integer' => 'Бројот на изводот е цел број.',
            'number.min' => 'Бројот на изводот почнува од 1.',
            'statementDate.required' => 'Внесете го датумот на изводот.',
            'statementDate.before_or_equal' => 'Датумот на изводот не може да биде во иднина.',
            'newFile.required' => 'Изберете го изводот што сакате да го качите.',
            'newFile.max' => 'Изводот не смее да биде поголем од 25 MB.',
        ]);

        // Двапати ист број во истата низа ја руши токму проверката поради која
        // бројот се внесува — прекинот потоа не се гледа.
        if ($this->numberIsTaken((int) $data['number'], $data['account'], $data['statementDate'])) {
            $this->addError('number', 'Извод со тој број за оваа сметка веќе постои.');

            return;
        }

        $statement = BankStatement::create([
            'company_id' => $this->company->id,
            'bank' => $data['bank'],
            'account' => $data['account'],
            'kind' => $data['kind'],
            'number' => $data['number'],
            'statement_date' => $data['statementDate'],
            'uploaded_by' => auth()->id(),
        ]);

        try {
            DocumentStorage::store($statement, $this->newFile, 'Bank Statement');
        } catch (\Throwable $e) {
            // Извод без фајл е ред во список што не води никаде.
            $statement->delete();

            throw $e;
        }

        $this->reset(['number', 'statementDate', 'newFile']);
    }

    /**
     * Увоз на MT940: ист праг како за книжење, зашто од фајлот се создаваат
     * ставки што сметководителот ги книжи.
     */
    public function importMt940Files(Mt940Importer $importer): void
    {
        Gate::authorize('view', $this->company);
        Gate::authorize('create', JournalEntry::class);

        $this->validate([
            'mt940Files' => ['required', 'array', 'min:1', 'max:60'],
            'mt940Files.*' => ['file', 'max:1024', 'extensions:txt,sta,mt940'],
        ], [
            'mt940Files.required' => 'Изберете барем една MT940 датотека.',
            'mt940Files.min' => 'Изберете барем една MT940 датотека.',
            'mt940Files.max' => 'Најмногу 60 датотеки одеднаш.',
            'mt940Files.*.max' => 'Датотеката не смее да биде поголема од 1 MB.',
            'mt940Files.*.extensions' => 'Датотеката мора да е .txt, .sta или .mt940.',
        ]);

        $items = collect($this->mt940Files)->map(fn ($file) => [
            'name' => basename($file->getClientOriginalName()),
            'text' => $file->get(),
            'file' => $file,
        ])->all();

        $this->importResults = array_map(
            fn ($outcome) => $outcome->toArray(),
            $importer->importMany($this->company, $items),
        );

        $this->reset('mt940Files');
    }

    /**
     * Брише извод во нацрт (со ставките и документот). Прокнижен не се брише —
     * прво се отвора за измена. Нужно кога изводот е увезен кај погрешна фирма.
     */
    public function deleteStatement(int $statementId): void
    {
        Gate::authorize('view', $this->company);
        Gate::authorize('create', JournalEntry::class);

        $statement = BankStatement::where('company_id', $this->company->id)->findOrFail($statementId);

        $this->resetErrorBag('delete');

        if ($statement->isBooked()) {
            $this->addError('delete', 'Прокнижен извод не може да се избрише. Прво отворете го за измена.');

            return;
        }

        DB::transaction(function () use ($statement) {
            $statement->documents()->delete();
            $statement->delete();
        });
    }

    private function numberIsTaken(int $number, string $account, string $date): bool
    {
        return StatementSequence::numberTaken($this->company->id, $account, $number, (int) substr($date, 0, 4));
    }

    public function resetFilters(): void
    {
        $this->reset(['statusFilter', 'kindFilter', 'accountFilter', 'dateFrom', 'dateTo', 'sortBy', 'sortDirection']);
    }

    /** Непозната вредност (изменета состојба на клиентот) се враќа на основната. */
    private function allowed(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : null;
    }

    /**
     * Од целата низа (со прекини) го остава само она што организаторот го бара.
     * Низата се чита од СИТЕ изводи — скриен прокнижен извод не е прекин.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array{0: array<int, array<string, mixed>>, 1: bool}  групи за приказ и дали се покажани прекините во низата
     */
    private function visibleGroups(array $groups): array
    {
        $status = $this->allowed($this->statusFilter, ['open', 'booked', 'all'], 'open');
        $kind = $this->allowed($this->kindFilter, array_merge([''], array_map(fn ($k) => $k->value, BankStatementKind::cases())), '');
        $from = $this->validDate($this->dateFrom);
        $to = $this->validDate($this->dateTo);
        $sortBy = $this->allowed($this->sortBy, ['number', 'date', 'uploaded'], 'number');
        $descending = $this->allowed($this->sortDirection, ['asc', 'desc'], 'asc') === 'desc';

        $narrowed = $status !== 'all' || $kind !== '' || $this->accountFilter !== '' || $from !== null || $to !== null;
        $gapsInline = ! $narrowed && $sortBy === 'number' && ! $descending;

        $visible = [];

        foreach ($groups as $group) {
            if ($this->accountFilter !== '' && $group['account'] !== $this->accountFilter) {
                continue;
            }

            if ($kind !== '' && $group['kind']->value !== $kind) {
                continue;
            }

            if ($gapsInline) {
                $visible[] = $group;

                continue;
            }

            $statements = collect($group['rows'])->where('type', 'statement')->pluck('statement')
                ->filter(fn (BankStatement $statement) => match ($status) {
                    'open' => ! $statement->isBooked(),
                    'booked' => $statement->isBooked(),
                    default => true,
                })
                ->filter(fn (BankStatement $statement) => ($from === null || $statement->statement_date->toDateString() >= $from)
                    && ($to === null || $statement->statement_date->toDateString() <= $to));

            $statements = $statements->sortBy(fn (BankStatement $statement) => match ($sortBy) {
                'date' => [$statement->statement_date->toDateString(), $statement->number],
                'uploaded' => [$statement->created_at?->toDateTimeString() ?? '', $statement->number],
                default => [$statement->number],
            }, SORT_REGULAR, $descending)->values();

            if ($statements->isEmpty()) {
                continue;
            }

            $visible[] = array_merge($group, [
                'rows' => $statements->map(fn ($statement) => ['type' => 'statement', 'statement' => $statement])->all(),
            ]);
        }

        return [$visible, $gapsInline];
    }

    /** Прекините во низата (од сите изводи), за предупредување кога не се покажуваат во табелата. */
    private function gapNotices(array $groups): array
    {
        $notices = [];

        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                if ($row['type'] === 'gap') {
                    $notices[] = ['bank' => $group['bank'], 'account' => $group['account'], 'year' => $group['year'], 'from' => $row['from'], 'to' => $row['to']];
                }
            }
        }

        return $notices;
    }

    public function render()
    {
        $statements = BankStatement::where('company_id', $this->company->id)
            ->with(['documents', 'uploader'])
            ->get();

        $year = WorkingYear::for($this->company);
        $canBook = auth()->user()?->hasAnyRole(['admin', 'accountant']) ?? false;

        $groups = StatementSequence::groups($statements);
        [$visible, $gapsInline] = $this->visibleGroups($groups);

        return view('livewire.bank.bank-statement-index', [
            'groups' => $groups,
            'visible' => $visible,
            'gapNotices' => $gapsInline ? [] : $this->gapNotices($groups),
            'hasStatements' => $statements->isNotEmpty(),
            'accountOptions' => $statements->groupBy('account')->map(fn ($rows, $account) => $rows->first()->bank.' — '.$account)->sortKeys()->all(),
            'kinds' => BankStatementKind::cases(),
            'canBook' => $canBook,
            'year' => $year,
            'totals' => $canBook ? BankLedgerTotals::forCompany($this->company, $year) : null,
        ]);
    }
}
