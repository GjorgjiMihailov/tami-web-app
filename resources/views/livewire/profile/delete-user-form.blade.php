<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            Избриши профил
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            Откако профилот ќе биде избришан, сите негови ресурси и податоци трајно ќе бидат избришани. Пред да го избришете профилот, преземете ги податоците или информациите што сакате да ги задржите.
        </p>
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >Избриши профил</x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable>
        <form wire:submit="deleteUser" class="p-6">

            <h2 class="text-lg font-medium text-gray-900">
                Дали сте сигурни дека сакате да го избришете профилот?
            </h2>

            <p class="mt-1 text-sm text-gray-600">
                Откако профилот ќе биде избришан, сите негови ресурси и податоци трајно ќе бидат избришани. Внесете ја вашата лозинка за да потврдите дека сакате трајно да го избришете профилот.
            </p>

            <div class="mt-6">
                <x-input-label for="password" value="Лозинка" class="sr-only" />

                <x-text-input
                    wire:model="password"
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-3/4"
                    placeholder="Лозинка"
                />

                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">
                    Откажи
                </x-secondary-button>

                <x-danger-button class="ms-3">
                    Избриши профил
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
