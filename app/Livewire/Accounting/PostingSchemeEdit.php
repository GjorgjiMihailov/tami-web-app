<?php

namespace App\Livewire\Accounting;

use App\Exceptions\PostingFormulaException;
use App\Exceptions\PostingSchemeException;
use App\Models\Account;
use App\Models\Company;
use App\Services\Posting\PostingSchemeEditor;
use App\Services\Posting\PostingSchemes;
use App\Services\Posting\PostingSchemeSets;
use App\Services\Posting\PostingSchemeTrial;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingVocabulary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PostingSchemeEdit extends Component
{
    public Company $company;

    public string $type = '';

    /** @var list<array<string, mixed>> работна копија на редовите */
    public array $rows = [];

    /** @var list<array<string, mixed>> матрични ќелии: matrix_key, item_kind, vat_group, label, account_code */
    public array $cells = [];

    /** Индекс на ред што се менува; -1 = нов; null = нема отворена форма. */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<string> */
    public array $problems = [];

    public bool $saved = false;

    public bool $mineSaved = false;

    public string $trialDocument = '';

    public bool $trialCash = false;

    /** @var array{lines: list<array<string, string>>, error: ?string}|null */
    public ?array $trial = null;

    public function mount(Company $company, string $type): void
    {
        Gate::authorize('update', $company);
        $this->company = $company;
        $this->type = (PostingDocType::tryFrom($type) ?? abort(404))->value;
        $this->loadDraft();
    }

    private function docType(): PostingDocType
    {
        return PostingDocType::from($this->type);
    }

    private function loadDraft(): void
    {
        $scheme = PostingSchemes::for($this->company, $this->docType());
        $draft = app(PostingSchemeEditor::class)->draftOf($scheme);

        $this->rows = $draft['rows'];

        $existing = collect($draft['matrix'])->keyBy(fn ($m) => $m['matrix_key'].'|'.($m['item_kind'] ?? '').'|'.$m['vat_group']);
        $this->cells = [];

        foreach (array_keys(PostingVocabulary::matrices($this->docType())) as $key) {
            foreach (PostingVocabulary::matrixCells($key) as $cell) {
                $cell['account_code'] = $existing[$cell['matrix_key'].'|'.($cell['item_kind'] ?? '').'|'.$cell['vat_group']]['account_code'] ?? '';
                $this->cells[] = $cell;
            }
        }

        $this->editing = null;
        $this->form = [];
        $this->problems = [];
        $this->saved = false;
        $this->mineSaved = false;
    }

    public function addRow(): void
    {
        $this->editing = -1;
        $this->form = ['account_mode' => 'fixed', 'account_code' => '', 'matrix_key' => '', 'side' => 'debit', 'formula' => '', 'with_partner' => false, 'description' => '', 'condition' => ''];
    }

    public function editRow(int $index): void
    {
        abort_unless(isset($this->rows[$index]), 404);

        $this->editing = $index;
        $this->form = array_merge($this->rows[$index], [
            'account_code' => (string) ($this->rows[$index]['account_code'] ?? ''),
            'matrix_key' => (string) ($this->rows[$index]['matrix_key'] ?? ''),
            'description' => (string) ($this->rows[$index]['description'] ?? ''),
            'condition' => (string) ($this->rows[$index]['condition'] ?? ''),
        ]);
    }

    public function cancelRow(): void
    {
        $this->editing = null;
        $this->form = [];
    }

    public function saveRow(): void
    {
        $type = $this->docType();

        $this->validate([
            'form.account_mode' => ['required', 'in:'.implode(',', array_keys(PostingVocabulary::modes($type)))],
            'form.side' => ['required', 'in:debit,credit'],
            'form.formula' => ['required', 'string', 'max:255'],
            'form.description' => ['nullable', 'string', 'max:255'],
            'form.account_code' => ['nullable', 'string', 'max:10'],
        ], [], ['form.formula' => 'формулата', 'form.account_mode' => 'начинот на конто', 'form.side' => 'страната']);

        $mode = $this->form['account_mode'];
        $row = [
            'account_mode' => $mode,
            'account_code' => $mode === 'fixed' ? trim((string) $this->form['account_code']) : null,
            'matrix_key' => $mode === 'matrix' ? (string) $this->form['matrix_key'] : null,
            'side' => $this->form['side'],
            'formula' => trim((string) $this->form['formula']),
            'with_partner' => (bool) ($this->form['with_partner'] ?? false),
            'description' => filled($this->form['description'] ?? null) ? $this->form['description'] : null,
            'condition' => filled($this->form['condition'] ?? null) ? $this->form['condition'] : null,
        ];

        if ($this->editing === -1) {
            $this->rows[] = $row;
        } else {
            $this->rows[$this->editing] = $row;
        }

        $this->editing = null;
        $this->form = [];
        $this->saved = false;
    }

    public function deleteRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
        $this->editing = null;
        $this->saved = false;
    }

    public function moveRow(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);

        if (! isset($this->rows[$index], $this->rows[$target])) {
            return;
        }

        [$this->rows[$index], $this->rows[$target]] = [$this->rows[$target], $this->rows[$index]];
        $this->editing = null;
        $this->saved = false;
    }

    /** @return list<array<string, mixed>> матрицата од ќелиите што имаат внесено конто */
    private function matrixFromCells(): array
    {
        return collect($this->cells)
            ->filter(fn ($c) => trim((string) $c['account_code']) !== '')
            ->map(fn ($c) => ['matrix_key' => $c['matrix_key'], 'item_kind' => $c['item_kind'], 'vat_group' => $c['vat_group'], 'account_code' => trim((string) $c['account_code'])])
            ->values()->all();
    }

    public function saveScheme(): void
    {
        Gate::authorize('update', $this->company);

        $scheme = PostingSchemes::for($this->company, $this->docType());
        $this->problems = app(PostingSchemeEditor::class)->save($scheme, $this->rows, $this->matrixFromCells());
        $this->saved = $this->problems === [];
    }

    public function runTrial(): void
    {
        Gate::authorize('update', $this->company);
        $this->trial = null;

        if ($this->trialDocument === '') {
            return;
        }

        $editor = app(PostingSchemeEditor::class);
        $type = $this->docType();
        $scheme = PostingSchemes::for($this->company, $type);
        $matrix = $this->matrixFromCells();
        $draft = $editor->transientScheme($this->company, $type, $scheme->name, $this->rows, $matrix);

        try {
            $lines = app(PostingSchemeTrial::class)->run($this->company, $type, (int) $this->trialDocument, $draft, $this->trialCash);

            $this->trial = [
                'error' => null,
                'lines' => array_map(fn ($l) => [
                    'account' => $l->account->code.' — '.$l->account->name,
                    'debit' => $l->side === 'debit' ? $l->amount : '',
                    'credit' => $l->side === 'credit' ? $l->amount : '',
                    'description' => $l->description,
                ], $lines),
            ];
        } catch (PostingSchemeException|PostingFormulaException $e) {
            $this->trial = ['lines' => [], 'error' => $e->getMessage()];
        }
    }

    /** Зачувај ја шемата (низ сите проверки) и запомни ја како мој предлог за нови фирми. */
    public function saveAsMine(): void
    {
        $this->saveScheme();
        $this->mineSaved = false;

        if ($this->problems !== []) {
            return;
        }

        PostingSchemeSets::remember(auth()->user(), PostingSchemes::for($this->company, $this->docType()));
        $this->mineSaved = true;
    }

    public function restoreDefault(): void
    {
        Gate::authorize('update', $this->company);

        try {
            app(PostingSchemeEditor::class)->resetToDefault(PostingSchemes::for($this->company, $this->docType()), auth()->user());
        } catch (ModelNotFoundException) {
            $this->problems = ['Некое конто од вашиот предлог го нема во планот на оваа фирма — шемата не е променета.'];

            return;
        }

        $this->loadDraft();
        $this->mineSaved = false;
    }

    public function render()
    {
        $type = $this->docType();

        $codes = collect($this->rows)->pluck('account_code')
            ->merge(collect($this->cells)->pluck('account_code'))
            ->push($this->form['account_code'] ?? null)
            ->filter()->unique()->values();

        $conditions = PostingVocabulary::conditions($type);

        return view('livewire.accounting.posting-scheme-edit', [
            'docType' => $type,
            'modes' => PostingVocabulary::modes($type),
            'conditions' => $conditions,
            'canCash' => array_key_exists('cash', $conditions),
            'hasMine' => PostingSchemeSets::has(auth()->user(), $type),
            'trialDocuments' => app(PostingSchemeTrial::class)->documents($this->company, $type),
            'variables' => PostingVocabulary::variables($type),
            'matrices' => PostingVocabulary::matrices($type),
            'accountNames' => Account::where('company_id', $this->company->id)->whereIn('code', $codes)->pluck('name', 'code'),
        ]);
    }
}
