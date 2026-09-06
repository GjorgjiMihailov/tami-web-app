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

    /**
     * Правилото „админ не може да си го одземе сопствениот пристап" мора да
     * важи и кога тој самиот не е сметка на оваа фирма (нормален случај —
     * канцеларијата нема company_id). Затоа целта прво се бара без
     * ограничување по фирма и се проверува правилото, па дури потоа се тврди
     * дека припаѓа тука. Обратниот редослед би фрлил 404 (корисникот не е
     * пронајден во опсегот на фирмата) наместо 403 токму за тој случај.
     */
    private function authorizedTarget(int $userId): User
    {
        $user = User::findOrFail($userId);

        Gate::authorize('disable', $user);

        abort_unless($user->company_id === $this->company->id, 403);

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
        ]);
    }
}
