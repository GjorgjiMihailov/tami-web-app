<?php

namespace App\Livewire\Bank;

use App\Models\BankStatement;
use App\Models\Company;
use App\Services\DocumentStorage;
use App\Support\Bank\StatementSequence;
use App\Support\BankStatementKind;
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

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;

        // Најчестата сметка е онаа што фирмата веќе ја има внесено во профилот.
        $configured = $company->bankAccounts()->first();
        $this->bank = $configured?->bank_name ?? '';
        $this->account = $configured?->account_number ?? '';
    }

    public function upload(): void
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

    private function numberIsTaken(int $number, string $account, string $date): bool
    {
        return BankStatement::where('company_id', $this->company->id)
            ->where('account', $account)
            ->where('number', $number)
            ->whereYear('statement_date', (int) substr($date, 0, 4))
            ->exists();
    }

    public function render()
    {
        $statements = BankStatement::where('company_id', $this->company->id)
            ->with(['documents', 'uploader'])
            ->get();

        return view('livewire.bank.bank-statement-index', [
            'groups' => StatementSequence::groups($statements),
            'kinds' => BankStatementKind::cases(),
        ]);
    }
}
