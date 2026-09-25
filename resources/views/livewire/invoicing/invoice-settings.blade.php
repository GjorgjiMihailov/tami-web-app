<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Формат на бројот на фактурата и профактурата</h1>
    <p class="text-sm text-gray-500 mb-4">
        Промената важи за фактурите што ќе ги потврдите и профактурите што ќе ги создадете отсега. Веќе издадените го задржуваат својот број.
    </p>

    <x-card class="max-w-2xl">
        <form wire:submit="save" class="grid gap-4">
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="includeYear" class="rounded border-gray-300">
                <span class="text-sm text-gray-700">Прикажи ја годината во бројот</span>
            </label>

            @if ($includeYear)
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="yearFirst" class="rounded border-gray-300">
                    <span class="text-sm text-gray-700">Годината оди прво (2026/1), инаку по бројот (1/2026)</span>
                </label>

                <div class="grid gap-3 md:grid-cols-2">
                    <div>
                        <x-input-label for="yearDigits" value="Цифри за годината" />
                        <select id="yearDigits" wire:model.live="yearDigits" class="w-full border-gray-300 rounded-md shadow-sm">
                            <option value="4">4 — 2026</option>
                            <option value="2">2 — 26</option>
                        </select>
                        @error('yearDigits') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-input-label for="separator" value="Разделник" />
                        <select id="separator" wire:model.live="separator" class="w-full border-gray-300 rounded-md shadow-sm">
                            <option value="/">Коса црта — /</option>
                            <option value="-">Цртичка — -</option>
                            <option value=".">Точка — .</option>
                            <option value="none">Без разделник</option>
                        </select>
                        @error('separator') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>
                </div>
            @endif

            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <x-input-label for="padding" value="Должина на бројот" />
                    <select id="padding" wire:model.live="padding" class="w-full border-gray-300 rounded-md shadow-sm">
                        <option value="1">1 — 1</option>
                        <option value="2">2 — 01</option>
                        <option value="3">3 — 001</option>
                        <option value="4">4 — 0001</option>
                        <option value="5">5 — 00001</option>
                        <option value="6">6 — 000001</option>
                    </select>
                    @error('padding') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="prefix" value="Префикс (незадолжително)" />
                    <x-text-input id="prefix" wire:model.live="prefix" class="w-full" placeholder="пр. ФА-" />
                    @error('prefix') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <x-input-label for="proformaPrefix" value="Префикс на профактурата (незадолжително)" />
                <x-text-input id="proformaPrefix" wire:model.live="proformaPrefix" class="w-full md:w-1/2" placeholder="пр. ПФ-" />
                <p class="text-xs text-gray-500 mt-1">Профактурите имаат своја серија, а годината, разделникот и должината се истите како кај фактурите.</p>
                @error('proformaPrefix') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div class="bg-gray-50 rounded-lg px-4 py-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Фактура</div>
                    <div class="text-xl font-semibold text-gray-800 mt-1">{{ $preview }}</div>
                </div>
                <div class="bg-gray-50 rounded-lg px-4 py-3">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Профактура</div>
                    <div class="text-xl font-semibold text-gray-800 mt-1">{{ $proformaPreview }}</div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <x-primary-button type="submit">Зачувај</x-primary-button>
                @if ($saved)
                    <span class="text-sm text-green-700">Зачувано.</span>
                @endif
            </div>
        </form>
    </x-card>
</div>
