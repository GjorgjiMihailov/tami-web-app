<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">{{ $company->name }}</h1>

    <x-tab-strip :tabs="$tabs" />

    {{-- Модулите важат само за правно лице — кај физичко лице типот веќе
         одлучува што се гледа. --}}
    @if ($company->type->isLegal())
        <x-card>
            <h2 class="font-semibold text-gray-700 mb-1">Што користи клиентот</h2>
            <p class="text-sm text-gray-600 mb-3">
                Исклучен модул исчезнува од менито и неговите екрани стануваат недостапни.
            </p>

            <form wire:submit="save" class="space-y-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="usesMaterial">
                    Материјално работење
                </label>
                <label class="flex items-center gap-2 text-sm ms-6 {{ $usesMaterial ? '' : 'text-gray-400' }}">
                    <input type="checkbox" wire:model="usesStock" @disabled(! $usesMaterial)>
                    Залиха
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="usesPayroll">
                    Плата
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="usesFinance">
                    Финансии
                </label>

                <div class="pt-3 flex items-center gap-3">
                    <x-primary-button>Зачувај</x-primary-button>
                    @if ($saved)
                        <span class="text-sm text-gray-600">Зачувано.</span>
                    @endif
                </div>
            </form>
        </x-card>
    @endif
</div>
