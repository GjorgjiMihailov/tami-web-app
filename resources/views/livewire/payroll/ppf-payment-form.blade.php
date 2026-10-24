<div>
    <a href="{{ route('ppf-payments.index', $company) }}" class="text-sm text-brand hover:underline">← Исплати на физички лица</a>
    <h1 class="text-2xl font-bold text-gray-800 mt-1 mb-4">
        {{ $payment ? 'Исплата на физичко лице' : 'Нова исплата на физичко лице' }} — {{ $company->name }}
    </h1>

    <form wire:submit="save">
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-3">Примател</h2>
            <div class="grid gap-3 md:grid-cols-3">
                <div class="md:col-span-3">
                    <x-input-label for="payeeId" value="Постоен примател" />
                    <select id="payeeId" wire:model.live="payeeId" class="border-gray-300 rounded-md text-sm w-full md:w-1/2">
                        <option value="">— нов примател —</option>
                        @foreach ($payees as $payee)
                            <option value="{{ $payee->id }}">{{ $payee->fullName() }} ({{ $payee->embg }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="name" value="Име" />
                    <x-text-input id="name" wire:model="name" class="w-full" />
                    @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="surname" value="Презиме" />
                    <x-text-input id="surname" wire:model="surname" class="w-full" />
                    @error('surname') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="embg" value="ЕМБГ" />
                    <x-text-input id="embg" wire:model="embg" class="w-full" />
                    @error('embg') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="municipalityCode" value="Општина" />
                    <select id="municipalityCode" wire:model="municipalityCode" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($municipalities as $code)
                            <option value="{{ $code->code }}">{{ $code->name }}</option>
                        @endforeach
                    </select>
                    @error('municipalityCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="bankAccount" value="Трансакциска сметка" />
                    <x-text-input id="bankAccount" wire:model="bankAccount" class="w-full" />
                    @error('bankAccount') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-card>

        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-3">Исплата</h2>
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <x-input-label for="paymentDate" value="Датум на исплата" />
                    <x-text-input id="paymentDate" type="date" wire:model.live="paymentDate" class="w-full" />
                    @error('paymentDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="md:col-span-2">
                    <x-input-label for="typeCode" value="Вид на приход" />
                    <select id="typeCode" wire:model.live="typeCode" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($types as $type)
                            <option value="{{ $type['code'] }}">{{ $type['code'] }} — {{ $type['name'] }}</option>
                        @endforeach
                    </select>
                    @error('typeCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="md:col-span-3">
                    <x-input-label for="subtypeCode" value="Подвид на приход" />
                    <select id="subtypeCode" wire:model="subtypeCode" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">—</option>
                        @foreach ($subtypes as $subtype)
                            <option value="{{ $subtype['code'] }}">{{ $subtype['code'] }} — {{ \Illuminate\Support\Str::limit($subtype['name'], 140) }}</option>
                        @endforeach
                    </select>
                    @if ($typeHasNoSubtypes)
                        <span class="text-amber-700 text-sm">Овој вид нема подвид во шифрарникот на УЈП, па XML фајл не може да се направи. Исплатата може да се зачува.</span>
                    @endif
                    @error('subtypeCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="gross" value="Бруто износ (цели денари)" />
                    <x-text-input id="gross" type="number" step="1" min="1" wire:model="gross" class="w-full" />
                    @error('gross') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="paymentTypeCode" value="Начин на плаќање" />
                    <select id="paymentTypeCode" wire:model.live="paymentTypeCode" class="border-gray-300 rounded-md text-sm w-full">
                        @foreach ($paymentTypes as $type)
                            <option value="{{ $type['code'] }}">{{ $type['name'] }}</option>
                        @endforeach
                    </select>
                    @error('paymentTypeCode') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>

            <details class="mt-4">
                <summary class="text-sm text-gray-600 cursor-pointer">Дополнителни полиња (ретко потребни)</summary>
                <div class="grid gap-3 md:grid-cols-3 mt-3">
                    <div>
                        <x-input-label for="advance" value="Аванс" />
                        <x-text-input id="advance" type="number" step="1" min="0" wire:model="advance" class="w-full" />
                        @error('advance') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="vatAmount" value="ДДВ износ (приходи од имот)" />
                        <x-text-input id="vatAmount" type="number" step="1" min="0" wire:model="vatAmount" class="w-full" />
                        @error('vatAmount') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="grossFree" value="Ослободен износ" />
                        <x-text-input id="grossFree" type="number" step="1" min="0" wire:model="grossFree" class="w-full" />
                        @error('grossFree') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="decisionNumber" value="Број на решение (странско лице)" />
                        <x-text-input id="decisionNumber" wire:model="decisionNumber" class="w-full" />
                        @error('decisionNumber') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="decisionDate" value="Датум на решение" />
                        <x-text-input id="decisionDate" type="date" wire:model="decisionDate" class="w-full" />
                        @error('decisionDate') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="comment" value="Коментар" />
                        <x-text-input id="comment" wire:model="comment" class="w-full" />
                        @error('comment') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                </div>
            </details>
        </x-card>

        <div class="flex gap-3">
            <x-primary-button type="submit">Зачувај</x-primary-button>
            <a href="{{ route('ppf-payments.index', $company) }}" class="px-4 py-2 text-sm text-gray-600 hover:underline">Откажи</a>
        </div>
    </form>
</div>
