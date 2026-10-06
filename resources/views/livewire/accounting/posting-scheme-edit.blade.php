<div>
    <div class="mb-3">
        <a href="{{ route('accounting.posting-schemes.index', $company) }}" class="text-sm text-brand hover:underline">← Шеми за книжење</a>
    </div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">{{ $docType->label() }} — {{ $company->name }}</h1>
    <p class="text-sm text-gray-600 mb-4 max-w-3xl">
        Менувате работна копија. Нема да се примени додека не притиснете „Зачувај“, а тогаш важи само за нови документи.
    </p>

    @if ($saved)
        <div class="mb-4 p-3 rounded bg-green-50 text-green-800 text-sm">Шемата е зачувана. Важи за нови документи.</div>
    @endif

    @if ($problems !== [])
        <div class="mb-4 p-3 rounded bg-red-50 text-red-800 text-sm">
            <p class="font-semibold mb-1">Шемата не е зачувана:</p>
            <ul class="list-disc ml-5 space-y-0.5">
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-card padding="p-0" class="overflow-hidden mb-4">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500 bg-gray-50">
                    <th class="py-1 px-3">#</th>
                    <th class="py-1 px-3">Конто</th>
                    <th class="py-1 px-3">Страна</th>
                    <th class="py-1 px-3">Износ (формула)</th>
                    <th class="py-1 px-3">Партнер</th>
                    <th class="py-1 px-3">Услов</th>
                    <th class="py-1 px-3">Опис</th>
                    <th class="py-1 px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($rows as $i => $row)
                    <tr class="text-sm hover:bg-orange-50">
                        <td class="py-1 px-3 text-gray-400">{{ $i + 1 }}</td>
                        <td class="py-1 px-3">
                            @if ($row['account_mode'] === 'fixed')
                                <span class="font-mono">{{ $row['account_code'] }}</span>
                                <span class="text-xs text-gray-500">{{ $accountNames[$row['account_code']] ?? '' }}</span>
                            @elseif ($row['account_mode'] === 'matrix')
                                <span class="text-gray-700">{{ $matrices[$row['matrix_key']] ?? $row['matrix_key'] }}</span>
                            @else
                                <span class="text-gray-700">{{ $modes[$row['account_mode']] ?? $row['account_mode'] }}</span>
                            @endif
                        </td>
                        <td class="py-1 px-3">{{ $row['side'] === 'debit' ? 'Должи' : 'Побарува' }}</td>
                        <td class="py-1 px-3 font-mono">{{ $row['formula'] }}</td>
                        <td class="py-1 px-3">{{ $row['with_partner'] ? 'Да' : '—' }}</td>
                        <td class="py-1 px-3 text-xs">{{ filled($row['condition'] ?? null) ? ($conditions[$row['condition']] ?? $row['condition']) : '' }}</td>
                        <td class="py-1 px-3 text-xs text-gray-500">{{ $row['description'] }}</td>
                        <td class="py-1 px-3 text-right whitespace-nowrap">
                            <button type="button" wire:click="moveRow({{ $i }}, -1)" class="text-gray-500 hover:text-gray-800" title="Горе">↑</button>
                            <button type="button" wire:click="moveRow({{ $i }}, 1)" class="text-gray-500 hover:text-gray-800" title="Долу">↓</button>
                            <button type="button" wire:click="editRow({{ $i }})" class="text-brand hover:underline ml-2">Измени</button>
                            <button type="button" wire:click="deleteRow({{ $i }})" wire:confirm="Да се избрише редот?" class="text-red-600 hover:underline ml-2">Избриши</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>

    @if ($editing === null)
        <div class="mb-6">
            <x-secondary-button type="button" wire:click="addRow">+ Нов ред</x-secondary-button>
        </div>
    @else
        <x-card class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-3">{{ $editing === -1 ? 'Нов ред' : 'Ред '.($editing + 1) }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <x-input-label for="form_mode" value="Конто" />
                    <select id="form_mode" wire:model.live="form.account_mode" class="border-gray-300 rounded-md text-sm w-full">
                        @foreach ($modes as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if (($form['account_mode'] ?? '') === 'fixed')
                    <div>
                        <x-input-label for="form_code" value="Шифра на конто (аналитичко)" />
                        <x-text-input id="form_code" wire:model.live.debounce.400ms="form.account_code" class="w-full font-mono" />
                        <p class="text-xs text-gray-500 mt-1">{{ $accountNames[$form['account_code'] ?? ''] ?? (filled($form['account_code'] ?? '') ? 'Непозната шифра.' : '') }}</p>
                    </div>
                @elseif (($form['account_mode'] ?? '') === 'matrix')
                    <div>
                        <x-input-label for="form_matrix" value="Матрица" />
                        <select id="form_matrix" wire:model="form.matrix_key" class="border-gray-300 rounded-md text-sm w-full">
                            <option value="">— изберете —</option>
                            @foreach ($matrices as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <div></div>
                @endif
                <div>
                    <x-input-label for="form_side" value="Страна" />
                    <select id="form_side" wire:model="form.side" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="debit">Должи</option>
                        <option value="credit">Побарува</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="form_formula" value="Износ (формула)" />
                    <x-text-input id="form_formula" wire:model="form.formula" class="w-full font-mono" />
                    @error('form.formula') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-input-label for="form_condition" value="Услов" />
                    <select id="form_condition" wire:model="form.condition" class="border-gray-300 rounded-md text-sm w-full">
                        <option value="">— секогаш —</option>
                        @foreach ($conditions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="form_description" value="Опис на ставката" />
                    <x-text-input id="form_description" wire:model="form.description" class="w-full" />
                </div>
            </div>
            <label class="inline-flex items-center gap-2 mt-3 text-sm">
                <input type="checkbox" wire:model="form.with_partner" class="rounded border-gray-300">
                Врзано за партнерот
            </label>
            <div class="mt-3 flex gap-2">
                <x-primary-button type="button" wire:click="saveRow">Прифати ред</x-primary-button>
                <x-secondary-button type="button" wire:click="cancelRow">Откажи</x-secondary-button>
            </div>
        </x-card>
    @endif

    @foreach ($matrices as $matrixKey => $matrixLabel)
        <div class="mb-6">
            <h2 class="font-semibold text-gray-700 mb-2">Матрица: {{ $matrixLabel }}</h2>
            <x-card padding="p-0" class="overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr class="text-left text-sm text-gray-500 bg-gray-50">
                            <th class="py-1 px-3">За</th>
                            <th class="py-1 px-3">Конто (шифра)</th>
                            <th class="py-1 px-3">Назив</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($cells as $i => $cell)
                            @if ($cell['matrix_key'] === $matrixKey)
                                <tr class="text-sm">
                                    <td class="py-1 px-3">{{ $cell['label'] }}</td>
                                    <td class="py-1 px-3"><x-text-input wire:model.live.debounce.400ms="cells.{{ $i }}.account_code" class="w-28 font-mono !py-0.5 text-sm" /></td>
                                    <td class="py-1 px-3 text-xs text-gray-500">{{ $accountNames[$cell['account_code']] ?? (filled($cell['account_code']) ? 'Непозната шифра.' : '') }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        </div>
    @endforeach

    @if ($mineSaved)
        <div class="mb-3 p-3 rounded bg-green-50 text-green-800 text-sm">Шемата е зачувана и е ваш предлог — ќе ја добива секоја нова фирма што ја создавате.</div>
    @endif

    <div class="flex flex-wrap gap-2 mb-2">
        <x-primary-button type="button" wire:click="saveScheme">Зачувај шема</x-primary-button>
        <x-secondary-button type="button" wire:click="saveAsMine" wire:confirm="Да се зачува шемата и да стане ваш предлог за нови фирми? Постојните фирми не се менуваат.">Зачувај и постави како мој предлог</x-secondary-button>
        <x-secondary-button type="button" wire:click="restoreDefault" wire:confirm="Да се врати предложената шема ({{ $hasMine ? 'вашиот личен предлог' : 'стандардната' }})? Вашите измени за овој документ ќе се изгубат.">Врати на предложено</x-secondary-button>
    </div>
    <p class="text-xs text-gray-500 mb-8">
        {{ $hasMine ? 'Имате личен предлог за овој документ.' : 'Немате личен предлог — „Врати на предложено“ ја враќа стандардната шема.' }}
        Предлогот важи само за нови фирми што ги создавате.
    </p>

    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-1">Пробај</h2>
        <p class="text-sm text-gray-600 mb-3">Избери постоечка фактура и види ги ставките што би настанале со шемата како што е сега (и со незачуваните измени). Ништо не се книжи.</p>
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[18rem]">
                <x-input-label for="trial_document" value="Документ" />
                <select id="trial_document" wire:model="trialDocument" class="border-gray-300 rounded-md text-sm w-full">
                    <option value="">— изберете —</option>
                    @foreach ($trialDocuments as $document)
                        <option value="{{ $document['id'] }}">{{ $document['label'] }}</option>
                    @endforeach
                </select>
            </div>
            @if ($canCash)
                <label class="inline-flex items-center gap-2 text-sm pb-2">
                    <input type="checkbox" wire:model="trialCash" class="rounded border-gray-300"> Готовинско
                </label>
            @endif
            <x-secondary-button type="button" wire:click="runTrial">Пробај</x-secondary-button>
        </div>
        @if ($trialDocuments->isEmpty())
            <p class="text-xs text-gray-500 mt-2">Нема потврдена фактура за пробање.</p>
        @endif

        @if ($trial !== null)
            @if ($trial['error'])
                <div class="mt-3 p-3 rounded bg-red-50 text-red-800 text-sm">{{ $trial['error'] }}</div>
            @else
                <table class="min-w-full divide-y divide-gray-200 mt-3">
                    <thead>
                        <tr class="text-left text-sm text-gray-500 bg-gray-50">
                            <th class="py-1 px-3">Конто</th>
                            <th class="py-1 px-3 text-right">Должи</th>
                            <th class="py-1 px-3 text-right">Побарува</th>
                            <th class="py-1 px-3">Опис</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($trial['lines'] as $line)
                            <tr class="text-sm">
                                <td class="py-1 px-3">{{ $line['account'] }}</td>
                                <td class="py-1 px-3 text-right font-mono">{{ $line['debit'] }}</td>
                                <td class="py-1 px-3 text-right font-mono">{{ $line['credit'] }}</td>
                                <td class="py-1 px-3 text-xs text-gray-500">{{ $line['description'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </x-card>

    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-2">Помош</h2>
        <p class="text-sm text-gray-600 mb-2">Во формулата може да се користат броеви, <span class="font-mono">+ − *</span>, загради и променливите:</p>
        <ul class="text-sm space-y-0.5 mb-3">
            @foreach ($variables as $name => $description)
                <li><span class="font-mono">{{ $name }}</span> — {{ $description }}</li>
            @endforeach
        </ul>
        <p class="text-xs text-gray-500">
            Во описот <span class="font-mono">{фактура}</span> се заменува со документот. Негативен износ ја менува страната (Должи ↔ Побарува).
            Износ нула не се книжи. Збирот Должи мора да е еднаков на Побарува.
        </p>
    </x-card>
</div>
