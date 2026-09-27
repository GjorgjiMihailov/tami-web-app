<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-ink">Здраво, {{ auth()->user()->name }}</h1>
        <p class="text-sm text-stone mt-1">Изберете фирма за да продолжите.</p>
    </div>

    @if ($companies->isEmpty())
        <x-card>
            <p class="text-stone">Немате пристап до ниту една фирма засега.
                <a href="{{ route('companies.index') }}" wire:navigate class="text-brand hover:underline">Управувај со фирми</a>
            </p>
        </x-card>
    @else
        {{-- Пребарувачот е клиентска филтрација врз списокот што и онака е
             веќе на страницата — нема потреба од барање до серверот за да се
             најде фирма меѓу неколку десетини. --}}
        <div x-data="{
                query: '',
                companies: {{ $companies->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'url' => route('companies.dashboard', $c)])->values()->toJson() }},
                open: false,
                get filtered() {
                    const q = this.query.trim().toLowerCase();
                    return q === '' ? this.companies : this.companies.filter(c => c.name.toLowerCase().includes(q));
                },
             }"
             class="relative mb-8 max-w-xl" @click.outside="open = false">
            <label for="company-search" class="sr-only">Барај фирма</label>
            <div class="relative">
                <svg class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-stone" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input id="company-search" type="text" x-model="query" @focus="open = true" autocomplete="off"
                       placeholder="Барај фирма по име..."
                       class="w-full rounded-full border border-sand bg-white py-3 pl-11 pr-4 text-sm text-ink shadow-card focus:border-brand focus:ring-1 focus:ring-brand focus:outline-none">
            </div>

            <div x-show="open" x-cloak x-transition.opacity.duration.150ms
                 class="absolute z-20 mt-2 w-full overflow-hidden rounded-2xl border border-sand bg-white shadow-xl">
                <template x-for="c in filtered" :key="c.id">
                    <a :href="c.url" wire:navigate
                       class="block px-4 py-2.5 text-sm text-ink hover:bg-canvas" x-text="c.name"></a>
                </template>
                <p x-show="filtered.length === 0" class="px-4 py-3 text-sm text-stone">Нема фирма со тоа име.</p>
            </div>
        </div>

        @if ($lastCompany)
            <a href="{{ route('companies.dashboard', $lastCompany) }}" wire:navigate
               class="press mb-6 flex items-center justify-between gap-3 rounded-2xl border border-sand bg-white px-5 py-4 shadow-card hover:border-brand/40">
                <span>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-brand">Продолжи</span>
                    <span class="block mt-0.5 text-base font-bold text-ink">{{ $lastCompany->name }}</span>
                </span>
                <svg class="h-5 w-5 text-brand" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12" />
                </svg>
            </a>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach ($companies as $company)
                <a href="{{ route('companies.dashboard', $company) }}" wire:navigate
                   class="app-tile press {{ $company->type->isLegal() ? 'app-tile--orange' : 'app-tile--indigo' }}"
                   style="--i: {{ $loop->index }}">
                    <span class="app-tile__glow" aria-hidden="true"></span>
                    <span class="app-tile__icon" aria-hidden="true">
                        <span class="text-sm font-bold">{{ \Illuminate\Support\Str::of($company->name)->substr(0, 2)->upper() }}</span>
                    </span>
                    <span class="relative block mt-4 text-base font-bold text-ink">{{ $company->name }}</span>
                    <span class="relative block mt-1 text-xs text-stone">{{ $company->type->label() }}</span>
                    <span class="app-tile__cta">
                        Отвори
                        <svg class="app-tile__arrow h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12" />
                        </svg>
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</div>
