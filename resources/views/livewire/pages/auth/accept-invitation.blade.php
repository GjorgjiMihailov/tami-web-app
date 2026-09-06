<?php

use App\Support\UserInvitations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function acceptInvitation(): void
    {
        $this->validate([
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = UserInvitations::accept($this->token, $this->password);

        // Една иста порака за секоја причина (истечен, употребен, исклучена
        // сметка) — екранот не смее да открива дали адресата постои.
        if ($user === null) {
            $this->addError('password', 'Линкот повеќе не важи. Побарајте нов од канцеларијата.');

            return;
        }

        Auth::login($user);
        Session::regenerate();

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <h2 class="text-lg font-semibold text-gray-800 mb-1">Постави лозинка</h2>
    <p class="text-sm text-gray-600 mb-4">Изберете лозинка со која ќе влегувате во порталот.</p>

    <form wire:submit="acceptInvitation">
        <div>
            <x-input-label for="password" value="Лозинка" />
            <x-text-input wire:model="password" id="password" class="block mt-1 w-full"
                          type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" value="Потврди лозинка" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full"
                          type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>Зачувај и влези</x-primary-button>
        </div>
    </form>
</div>
