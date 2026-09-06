<?php

namespace App\Livewire;

use App\Livewire\Concerns\SendsInvitations;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Сметките на канцеларијата — админи и сметководители. Тие не припаѓаат на
 * фирма, затоа не седат на картичките кај клиент.
 */
#[Layout('layouts.app')]
class OfficeUsers extends Component
{
    use SendsInvitations;

    public const ROLES = ['accountant' => 'Сметководител', 'admin' => 'Админ'];

    public string $newName = '';

    public string $newEmail = '';

    public string $newRole = 'accountant';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);
    }

    public function addUser(): void
    {
        Gate::authorize('create', User::class);

        $validated = $this->validate([
            'newName' => 'required|string|max:255',
            'newEmail' => 'required|email|max:255|unique:users,email',
            // Клиентска сметка се отвора само од картичката кај својата фирма,
            // за да не може да настане клиент без фирма.
            'newRole' => ['required', Rule::in(array_keys(self::ROLES))],
        ], [
            'newEmail.unique' => 'Оваа е-пошта веќе има сметка во порталот.',
            'newRole.in' => 'Клиентска сметка се отвора кај самата фирма.',
        ]);

        $user = User::create([
            'name' => $validated['newName'],
            'email' => $validated['newEmail'],
            'password' => Str::random(64),
        ]);

        $user->assignRole($validated['newRole']);

        $this->sendInvitation($user);

        $this->reset(['newName', 'newEmail']);
    }

    public function reinvite(int $userId): void
    {
        $user = $this->officeUser($userId);

        Gate::authorize('invite', $user);

        $this->sendInvitation($user);
    }

    public function disable(int $userId): void
    {
        $user = $this->officeUser($userId);

        Gate::authorize('disable', $user);

        $user->forceFill(['disabled_at' => now()])->save();
    }

    public function enable(int $userId): void
    {
        $user = $this->officeUser($userId);

        Gate::authorize('disable', $user);

        $user->forceFill(['disabled_at' => null])->save();
    }

    private function officeUser(int $userId): User
    {
        return User::role(array_keys(self::ROLES))->findOrFail($userId);
    }

    public function render()
    {
        return view('livewire.office-users', [
            'users' => User::with(['latestInvitation', 'roles'])
                ->role(array_keys(self::ROLES))
                ->orderBy('name')
                ->get(),
        ]);
    }
}
