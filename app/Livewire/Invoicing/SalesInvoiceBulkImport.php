<?php

namespace App\Livewire\Invoicing;

use App\Imports\SalesInvoicesImport;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Services\Invoicing\SalesInvoiceImportParser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Масовен внес на излезни фактури од Excel (фирма што се презема од друга
 * програма). Создава НАЦРТИ без ставки со бројот од хартија; ставките ги
 * додава корисникот рачно, а потврдата си го прави вообичаениот пат.
 */
#[Layout('layouts.app')]
class SalesInvoiceBulkImport extends Component
{
    use WithFileUploads;

    public Company $company;

    public $importFile = null;

    /** Сурови ќелии од листот — при потврда повторно се проверуваат (состојбата на клиентот не е доверлива). */
    public array $rawRows = [];

    public array $parsedRows = [];

    /** Избор по група нејасни имиња: '' | 'new' | 'skip' | ID на постоечки кооперант. */
    public array $partnerChoices = [];

    public ?string $summary = null;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);

        $this->company = $company;
    }

    public function preview(): void
    {
        $this->authorizeImport();
        $this->validate(['importFile' => 'required|file|max:5120']);
        $this->resetErrorBag('importFile');

        try {
            $sheets = Excel::toArray(new SalesInvoicesImport, $this->importFile);
        } catch (\Throwable) {
            $this->addError('importFile', 'Фајлот не можеше да се прочита како табела (.xlsx или .csv). Проверете дека е образецот.');
            $this->importFile = null;

            return;
        }

        $rows = $sheets[0] ?? [];

        try {
            $parsed = app(SalesInvoiceImportParser::class)->parse($rows, $this->company->id);
        } catch (\InvalidArgumentException $e) {
            $this->addError('importFile', $e->getMessage());
            $this->importFile = null;

            return;
        }

        $this->rawRows = $rows;
        $this->parsedRows = $parsed;
        $this->partnerChoices = collect($this->groupsOf($parsed))->mapWithKeys(fn ($group, $key) => [$key => ''])->all();
        $this->summary = null;
        $this->importFile = null;
    }

    public function confirmImport(): void
    {
        $this->authorizeImport();
        $this->resetErrorBag('confirm');

        if ($this->rawRows === []) {
            return;
        }

        // Повторна проверка: бројот можел да биде зафатен откако е прикажан прегледот.
        $rows = app(SalesInvoiceImportParser::class)->parse($this->rawRows, $this->company->id);
        $groups = $this->groupsOf($rows);

        foreach (array_keys($groups) as $key) {
            if (($this->partnerChoices[$key] ?? '') === '') {
                $this->addError('confirm', 'Изберете што да се прави со секоја нејасна фирма (кооперант, нов кооперант или прескокни).');

                return;
            }
        }

        $companyPartners = Partner::where('company_id', $this->company->id)->get()->keyBy('id');

        foreach ($groups as $key => $group) {
            $choice = $this->partnerChoices[$key];

            if (! in_array($choice, ['new', 'skip'], true) && ! $companyPartners->has((int) $choice)) {
                $this->addError('confirm', 'Избраниот кооперант не припаѓа на оваа фирма.');

                return;
            }
        }

        $created = 0;
        $skipped = 0;

        try {
            DB::transaction(function () use ($rows, $groups, $companyPartners, &$created, &$skipped): void {
                $newPartners = [];

                foreach ($rows as $row) {
                    if ($row['status'] === 'error') {
                        $skipped++;

                        continue;
                    }

                    $partnerId = $row['partner_id'];

                    if ($row['status'] === 'unmatched') {
                        $key = $this->groupKey($row);
                        $choice = $this->partnerChoices[$key];

                        if ($choice === 'skip') {
                            $skipped++;

                            continue;
                        }

                        if ($choice === 'new') {
                            $newPartners[$key] ??= $this->createPartner($groups[$key]);
                            $partnerId = $newPartners[$key]->id;
                        } else {
                            $partnerId = (int) $choice;
                        }
                    }

                    $partner = $newPartners[$this->groupKey($row)] ?? $companyPartners->get($partnerId);
                    $date = Carbon::parse($row['date']);

                    SalesInvoice::create([
                        'company_id' => $this->company->id,
                        'partner_id' => $partnerId,
                        'invoice_date' => $date->toDateString(),
                        'due_date' => ($partner?->payment_terms_days !== null
                            ? $date->copy()->addDays($partner->payment_terms_days)
                            : $date)->toDateString(),
                        'status' => 'draft',
                        'invoice_number_formatted' => $row['number'],
                        'is_imported' => true,
                        'imported_total' => $row['total'],
                        'created_by' => auth()->id(),
                    ]);

                    $created++;
                }
            });
        } catch (\Throwable $e) {
            report($e);
            $this->addError('confirm', 'Настана грешка при зачувување. Ништо не е зачувано — обидете се повторно.');

            return;
        }

        $this->summary = "{$created} нацрт-фактури се увезени."
            .($skipped > 0 ? " {$skipped} се прескокнати." : '')
            .' Отворете ја секоја и додајте ставки.';
        $this->rawRows = [];
        $this->parsedRows = [];
        $this->partnerChoices = [];
    }

    /** Нејасните редови групирани по име на кооперант — еден избор важи за сите. */
    private function groupsOf(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            if ($row['status'] !== 'unmatched') {
                continue;
            }

            $key = $this->groupKey($row);

            $groups[$key] ??= ['name' => $row['partner_name'], 'tax_id' => '', 'count' => 0];
            $groups[$key]['count']++;

            if ($groups[$key]['tax_id'] === '' && $row['tax_id'] !== '') {
                $groups[$key]['tax_id'] = $row['tax_id'];
            }
        }

        return $groups;
    }

    private function groupKey(array $row): string
    {
        return md5(SalesInvoiceImportParser::normalizeName($row['partner_name']));
    }

    private function createPartner(array $group): Partner
    {
        Gate::authorize('create', Partner::class);

        return Partner::create([
            'company_id' => $this->company->id,
            'name' => $group['name'],
            'type' => 'legal_entity',
            'tax_id' => $group['tax_id'] !== '' ? $group['tax_id'] : null,
        ]);
    }

    private function authorizeImport(): void
    {
        Gate::authorize('create', SalesInvoice::class);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        Gate::authorize('view', $this->company);
    }

    public function render()
    {
        $rows = collect($this->parsedRows);

        return view('livewire.invoicing.sales-invoice-bulk-import', [
            'groups' => $this->groupsOf($this->parsedRows),
            'partners' => $rows->isEmpty() ? collect() : Partner::where('company_id', $this->company->id)->orderBy('name')->get(['id', 'name']),
            'readyCount' => $rows->where('status', 'ready')->count(),
            'unmatchedCount' => $rows->where('status', 'unmatched')->count(),
            'errorCount' => $rows->where('status', 'error')->count(),
        ]);
    }
}
