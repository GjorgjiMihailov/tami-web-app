<?php

namespace App\Livewire\Bank;

use App\Models\Account;
use App\Models\BankLineRule;
use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * „Запомнати конта“: што програмата го нуди при увоз на извод, по сметка на
 * другата страна, насока и вид на текст. Сметководителот го менува контото или
 * го брише правилото; важи за следните увози, прокнижените изводи не се менуваат.
 */
#[Layout('layouts.app')]
class BankLineRuleIndex extends Component
{
    use WithPagination;

    public Company $company;

    public string $search = '';

    /** Избрано конто по правило (id на правило → id на конто), за изборникот. */
    public array $accountIds = [];

    public function mount(Company $company): void
    {
        $this->authorizeCompany($company);

        $this->company = $company;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Изборникот го поставува $accountIds[<id на правило>]; се зачувува веднаш. */
    public function updatedAccountIds($value, $ruleId): void
    {
        $this->authorizeCompany($this->company);

        $rule = BankLineRule::where('company_id', $this->company->id)->findOrFail($ruleId);
        $account = Account::where('company_id', $this->company->id)->analytical()->where('is_active', true)->find($value);

        if ($account === null) {
            // Празно значи дека полето е избришано без нов избор: само се враќа старото.
            if ($value !== '' && $value !== null) {
                $this->addError("accountIds.{$ruleId}", 'Изберете постоечко активно аналитичко конто.');
            }

            $this->accountIds[$ruleId] = $rule->account_id;

            return;
        }

        $rule->update(['account_id' => $account->id]);
        $this->resetErrorBag("accountIds.{$ruleId}");
    }

    public function deleteRule(int $ruleId): void
    {
        $this->authorizeCompany($this->company);

        BankLineRule::where('company_id', $this->company->id)->whereKey($ruleId)->delete();
        unset($this->accountIds[$ruleId]);
    }

    private function authorizeCompany(Company $company): void
    {
        Gate::authorize('view', $company);
        Gate::authorize('create', JournalEntry::class);
    }

    public function render()
    {
        $term = trim($this->search);

        $rules = BankLineRule::where('company_id', $this->company->id)
            ->with(['account:id,code,name', 'partner:id,name'])
            ->when($term !== '', function ($query) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $query->where(fn ($q) => $q->where('name_seen', 'like', $like)
                    ->orWhere('pattern', 'like', $like)
                    ->orWhere('example_text', 'like', $like));
            })
            ->orderBy('name_seen')
            ->orderBy('direction')
            ->orderBy('pattern')
            ->paginate(50);

        // Првичната вредност на изборникот; тековниот избор на клиентот не се пребришува.
        foreach ($rules as $rule) {
            $this->accountIds[$rule->id] ??= $rule->account_id;
        }

        $accounts = Account::where('company_id', $this->company->id)->analytical()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);

        return view('livewire.bank.bank-line-rule-index', [
            'rules' => $rules,
            'accountsForJs' => $accounts->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} — {$a->name}"])->values(),
        ]);
    }
}
