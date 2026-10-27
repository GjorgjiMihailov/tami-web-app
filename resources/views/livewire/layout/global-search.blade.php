<div x-data="{ open: false }"
     @keydown.window.ctrl.k.prevent="open = true; $nextTick(() => $refs.q.focus())"
     @keydown.window.meta.k.prevent="open = true; $nextTick(() => $refs.q.focus())"
     @keydown.escape.window="open = false">
    {{-- Кутијата на лентата е само копче: вистинското пребарување е панелот. --}}
    <button type="button" @click="open = true; $nextTick(() => $refs.q.focus())" aria-label="Барај"
            class="hidden md:flex h-9 w-64 lg:w-80 items-center gap-2 rounded-full bg-canvas px-3 text-sm text-stone hover:bg-sand/60 press">
        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z" /></svg>
        <span class="flex-1 truncate text-left">{{ $company ? 'Барај фактура, кооперант…' : 'Барај клиент…' }}</span>
        <kbd class="rounded border border-sand px-1.5 text-[11px] text-stone">Ctrl K</kbd>
    </button>
    <button type="button" @click="open = true; $nextTick(() => $refs.q.focus())" aria-label="Барај"
            class="md:hidden inline-flex h-9 w-9 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 press">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z" /></svg>
    </button>

    <div x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-start justify-center px-4 pt-[12vh]">
        <div x-transition.opacity @click="open = false" class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm"></div>

        <div x-transition class="relative w-full max-w-xl overflow-hidden rounded-2xl border border-sand bg-white shadow-card">
            <div class="flex items-center gap-3 border-b border-sand px-4">
                <svg class="h-5 w-5 shrink-0 text-stone" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z" /></svg>
                <input x-ref="q" type="text" wire:model.live.debounce.250ms="query" autocomplete="off"
                       placeholder="{{ $company ? 'Број на фактура, име или ЕДБ на кооперант' : 'Име или ЕДБ на клиент' }}"
                       class="h-12 w-full border-0 bg-transparent px-0 text-sm text-ink placeholder:text-stone focus:ring-0">
                <button type="button" @click="open = false" class="rounded border border-sand px-1.5 text-[11px] text-stone">Esc</button>
            </div>

            <div class="max-h-[60vh] overflow-y-auto p-2">
                @forelse ($groups as $group)
                    <p class="px-2 pb-1 pt-2 text-xs font-semibold tracking-wide text-stone">{{ mb_strtoupper($group['title']) }}</p>
                    @foreach ($group['rows'] as $row)
                        <a href="{{ $row['url'] }}" class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm hover:bg-orange-50">
                            <span class="truncate font-medium text-ink">{{ $row['primary'] }}</span>
                            <span class="truncate text-xs text-stone">{{ $row['secondary'] }}</span>
                        </a>
                    @endforeach
                @empty
                    <p class="px-2 py-6 text-center text-sm text-stone">
                        {{ mb_strlen(trim($query)) < 2 ? 'Внеси барем два знака.' : 'Нема резултати за „'.trim($query).'“.' }}
                    </p>
                @endforelse
            </div>
        </div>
    </div>
</div>
