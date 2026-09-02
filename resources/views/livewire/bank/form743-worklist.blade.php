<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">743 обрасци за внесување</h1>
    <p class="text-sm text-gray-500 mb-4">
        Обрасците подолу чекаат пријава во е-ПДД. Внесувањето се прави рачно на
        порталот на УЈП — овој список кажува што останало и го чува тоа што е внесено.
    </p>

    <x-card>
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 bg-gray-50">
                    <th class="py-1">Клиент</th>
                    <th class="py-1">Датотека</th>
                    <th class="py-1">Прикачено</th>
                    <th class="py-1">Прикачил</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($forms as $form)
                    <tr class="hover:bg-orange-50">
                        <td class="py-1">{{ $form->company->name }}</td>
                        <td class="py-1">
                            @if ($form->documents->isNotEmpty())
                                <a href="{{ route('form743.download', [$form->company_id, $form]) }}" class="text-brand hover:underline">
                                    {{ $form->documents->first()->original_filename }}
                                </a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-1">{{ \App\Support\Format::date($form->created_at) }}</td>
                        <td class="py-1">{{ $form->uploader?->name }}</td>
                        <td class="py-1 text-right">
                            @if ($editingId !== $form->id)
                                <button type="button" wire:click="edit({{ $form->id }})" class="text-brand hover:underline">Обработи</button>
                            @endif
                        </td>
                    </tr>

                    @if ($editingId === $form->id)
                        <tr class="bg-orange-50/40">
                            <td colspan="5" class="py-3">
                                <form wire:submit="save" class="flex flex-wrap gap-3 items-end">
                                    <div class="flex-1 min-w-[14rem]">
                                        <x-input-label for="payer" value="Плаќач" />
                                        <x-text-input id="payer" wire:model="payer" class="w-full" />
                                        @error('payer') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="w-32">
                                        <x-input-label for="amount" value="Износ" />
                                        <x-text-input id="amount" wire:model="amount" class="w-full text-right" />
                                        @error('amount') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="w-24">
                                        <x-input-label for="currency" value="Девиза" />
                                        <x-text-input id="currency" wire:model="currency" class="w-full uppercase" maxlength="3" />
                                        @error('currency') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="w-40">
                                        <x-input-label for="paymentDate" value="Датум на исплата" />
                                        <x-text-input id="paymentDate" type="date" wire:model="paymentDate" class="w-full" />
                                        @error('paymentDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="flex-1 min-w-[14rem]">
                                        <x-input-label for="basis" value="Основ" />
                                        <x-text-input id="basis" wire:model="basis" class="w-full" />
                                        @error('basis') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                                    </div>
                                    <x-primary-button type="submit">Зачувај и затвори</x-primary-button>
                                    <button type="button" wire:click="cancel" class="text-sm text-gray-500 hover:underline">Откажи</button>
                                </form>
                                <p class="text-xs text-gray-500 mt-2">
                                    Кликнете „Зачувај и затвори" откако пријавата е внесена во е-ПДД.
                                </p>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">Нема необработени обрасци.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</div>
