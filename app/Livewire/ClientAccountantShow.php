<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesClientProfiles;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Страница на сметководител за админот: податоци, фирми на кои работи, линк за
 * нова лозинка, исклучување и бришење.
 */
#[Layout('layouts.app')]
class ClientAccountantShow extends Component
{
    use ManagesClientProfiles;

    public User $accountant;

    public function mount(User $user): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);
        abort_unless($user->hasRole('accountant'), 404);

        $this->accountant = $user;
    }

    protected function afterDelete(): void
    {
        $this->redirect(route('clients.index'), navigate: true);
    }

    public function render()
    {
        $this->accountant->refresh()->load(['latestInvitation', 'assignedCompanies' => fn ($q) => $q->orderBy('name')]);

        return view('livewire.client-accountant-show');
    }
}
