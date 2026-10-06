<?php

namespace App\Livewire\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Services\Posting\PostingSchemeEditor;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingVocabulary;
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

    public function saveScheme(): void
    {
        Gate::authorize('update', $this->company);

        $matrix = collect($this->cells)
            ->filter(fn ($c) => trim((string) $c['account_code']) !== '')
            ->map(fn ($c) => ['matrix_key' => $c['matrix_key'], 'item_kind' => $c['item_kind'], 'vat_group' => $c['vat_group'], 'account_code' => trim((string) $c['account_code'])])
            ->values()->all();

        $scheme = PostingSchemes::for($this->company, $this->docType());
        $this->problems = app(PostingSchemeEditor::class)->save($scheme, $this->rows, $matrix);
        $this->saved = $this->problems === [];
    }

    public function restoreDefault(): void
    {
        Gate::authorize('update', $this->company);

        app(PostingSchemeEditor::class)->resetToDefault(PostingSchemes::for($this->company, $this->docType()));
        $this->loadDraft();
    }

    public function render()
    {
        $type = $this->docType();

        $codes = collect($this->rows)->pluck('account_code')
            ->merge(collect($this->cells)->pluck('account_code'))
            ->push($this->form['account_code'] ?? null)
            ->filter()->unique()->values();

        return view('livewire.accounting.posting-scheme-edit', [
            'docType' => $type,
            'modes' => PostingVocabulary::modes($type),
            'conditions' => PostingVocabulary::conditions($type),
            'variables' => PostingVocabulary::variables($type),
            'matrices' => PostingVocabulary::matrices($type),
            'accountNames' => Account::where('company_id', $this->company->id)->whereIn('code', $codes)->pluck('name', 'code'),
        ]);
    }
}
