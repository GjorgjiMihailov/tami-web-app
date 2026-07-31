<div class="max-w-3xl mx-auto py-6">
    <h1 class="text-lg font-semibold mb-4">Чекаат одобрување — фирмени е-Фактура акредитиви</h1>

    @if ($pendingCompanies->isEmpty())
        <p class="text-gray-500">Нема барања што чекаат одобрување.</p>
    @else
        <div class="space-y-3">
            @foreach ($pendingCompanies as $company)
                <x-card>
                    <div class="flex items-center justify-between">
                        <span>{{ $company->name }}</span>
                        <span class="hidden" data-company-id="{{ $company->id }}"></span>
                        <div class="flex gap-2">
                            <button wire:click="approve({{ $company->id }})" type="button" class="rounded-full bg-green-600 text-white px-4 py-1.5 text-sm">Одобри</button>
                            <button wire:click="reject({{ $company->id }})" type="button" class="rounded-full bg-red-600 text-white px-4 py-1.5 text-sm">Одбиј</button>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</div>
