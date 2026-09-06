<?php

namespace App\Livewire;

use App\Livewire\Concerns\SendsInvitations;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyTabs;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CompanyUsers extends Component
{
    use SendsInvitations;

    public Company $company;

    public string $newName = '';

    public string $newEmail = '';

    public string $accountantToAssign = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
    }

    public function addUser(): void
    {
        Gate::authorize('create', User::class);

        $validated = $this->validate([
            'newName' => 'required|string|max:255',
            'newEmail' => 'required|email|max:255|unique:users,email',
        ], [
            // Намерно не кажува во која фирма е таа адреса.
            'newEmail.unique' => 'Оваа е-пошта веќе има сметка во порталот.',
        ]);

        $user = User::create([
            'name' => $validated['newName'],
            'email' => $validated['newEmail'],
            // Со оваа лозинка не може да се влезе. Се поставува вистинска дури
            // преку поканата.
            'password' => Str::random(64),
        ]);

        // company_id не е во #[Fillable] на моделот.
        $user->forceFill(['company_id' => $this->company->id])->save();
        $user->assignRole('client');

        $this->sendInvitation($user);

        $this->reset(['newName', 'newEmail']);
    }

    public function reinvite(int $userId): void
    {
        $user = $this->companyUser($userId);

        Gate::authorize('invite', $user);

        $this->sendInvitation($user);
    }

    public function disable(int $userId): void
    {
        $user = $this->authorizedTarget($userId);

        $user->forceFill(['disabled_at' => now()])->save();
    }

    public function enable(int $userId): void
    {
        $user = $this->authorizedTarget($userId);

        $user->forceFill(['disabled_at' => null])->save();
    }

    public function assignAccountant(int $userId): void
    {
        Gate::authorize('create', User::class);

        $accountant = User::role('accountant')->findOrFail($userId);

        // syncWithoutDetaching, не attach: двоен клик не смее да остави два реда.
        $this->company->accountants()->syncWithoutDetaching([$accountant->id]);

        $this->accountantToAssign = '';
    }

    public function removeAccountant(int $userId): void
    {
        Gate::authorize('create', User::class);

        $this->company->accountants()->detach($userId);
    }

    /**
     * Правилото „админ не може да си го одземе сопствениот пристап" мора да
     * важи и кога тој самиот не е сметка на оваа фирма (нормален случај —
     * канцеларијата нема company_id). Проверката е чиста споредба на бројот
     * — без барање кон базата — па се случува пред сѐ друго и важи без
     * разлика на company_id на актерот.
     *
     * Секој друг случај минува низ истиот опсег по фирма како reinvite()
     * (companyUser()), па дури потоа правилото. Порано целта се бараше
     * ГЛОБАЛНО (User::findOrFail без опсег), што отвораше дупка: за актер
     * што не е админ правилото секогаш пропаѓа, па 403 значеше „ID-то постои
     * некаде во порталот", а 404 значеше „ID-то не постои никаде" — клиент
     * можеше да проба туѓи ID-иња и да дознае кои сметки постојат во ТУЃИ
     * фирми. Со опсег по фирма прв, ID од туѓа фирма и ID што воопшто не
     * постои даваат ист исход (ModelNotFoundException) — пробирањето
     * останува заклучено во сопствената фирма, точно како што бришефот
     * бараше.
     */
    private function authorizedTarget(int $userId): User
    {
        if (auth()->id() === $userId) {
            abort(403);
        }

        $user = $this->companyUser($userId);

        Gate::authorize('disable', $user);

        return $user;
    }

    /**
     * Никогаш не се работи со корисник надвор од оваа фирма, ниту кога бројот
     * дојде преку жица.
     */
    private function companyUser(int $userId): User
    {
        return User::where('company_id', $this->company->id)->findOrFail($userId);
    }

    public function render()
    {
        return view('livewire.company-users', [
            'users' => User::with('latestInvitation')
                ->where('company_id', $this->company->id)
                ->orderBy('name')
                ->get(),
            'tabs' => CompanyTabs::for(auth()->user(), $this->company, 'companies.users'),
            'assigned' => $this->company->accountants()->orderBy('name')->get(),
            'available' => User::role('accountant')
                ->whereDoesntHave('assignedCompanies', fn ($query) => $query->whereKey($this->company->id))
                ->orderBy('name')
                ->get(),
        ]);
    }
}
