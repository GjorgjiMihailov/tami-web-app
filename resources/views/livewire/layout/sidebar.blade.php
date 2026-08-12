<div class="w-60 shrink-0 bg-white border-r border-gray-100 text-gray-700 flex flex-col min-h-screen">
    <div class="px-4 py-4 border-b border-gray-100">
        <a href="{{ route('dashboard') }}" wire:navigate class="font-bold text-brand text-sm">
            {{ config('app.name', 'Laravel') }}
        </a>
    </div>

    <nav class="flex-1 py-3 space-y-1">
        @if (auth()->check() && auth()->user()->hasRole('admin'))
            <a href="{{ route('dashboard') }}" wire:navigate
               class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ $currentRoute === 'dashboard' ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                Почетна
            </a>
            <a href="{{ route('companies.index') }}" wire:navigate
               class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ $currentRoute === 'companies.index' ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                Фирми
            </a>
        @endif

        @if ($company)
            <div class="pt-4 mt-3 border-t border-gray-100">
                <div class="px-4 pb-3 space-y-2">
                    <label class="flex items-center gap-2 text-xs text-gray-500">
                        <span>Фирма</span>
                        <select onchange="if (this.value) window.location.href = this.value"
                                class="flex-1 rounded-lg border-gray-200 text-sm py-1 text-gray-700 focus:border-brand focus:ring-brand">
                            @foreach ($companyOptions as $option)
                                <option value="{{ route('companies.dashboard', $option['id']) }}"
                                        @selected($option['id'] === $company->id)>{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-xs text-gray-500">
                        <span>Година</span>
                        <select wire:model.live="workingYear"
                                class="flex-1 rounded-lg border-gray-200 text-sm py-1 text-gray-700 focus:border-brand focus:ring-brand">
                            @foreach ($availableYears as $year)
                                <option value="{{ $year }}">{{ $year }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                @foreach ($menu as $group)
                    <button type="button" wire:click="toggleGroup('{{ $group['key'] }}')"
                            class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium rounded-lg mx-3 text-gray-600 hover:bg-orange-50"
                            style="width: calc(100% - 1.5rem);">
                        <span>{{ $group['label'] }}</span>
                        <span>{{ $expandedGroup === $group['key'] ? '−' : '+' }}</span>
                    </button>
                    @if ($expandedGroup === $group['key'])
                        <div class="pl-6">
                            @foreach ($group['items'] as $item)
                                <a href="{{ $item['url'] }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-1.5 text-sm {{ $this->isActive($item['pattern']) ? 'text-brand font-medium' : ($item['soon'] ? 'text-gray-400 hover:text-gray-600' : 'text-gray-500 hover:text-gray-800') }}">
                                    <span>{{ $item['label'] }}</span>
                                    @if ($item['soon'])
                                        <span class="text-[10px] uppercase tracking-wide text-gray-400">наскоро</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endforeach

                <a href="{{ route('documents.index', $company) }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 mt-1 {{ str_starts_with($currentRoute, 'documents.') ? 'bg-brand text-white' : 'text-gray-600 hover:bg-orange-50' }}">
                    Документи
                </a>
            </div>
        @endif
    </nav>
</div>
