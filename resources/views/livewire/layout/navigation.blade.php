<?php

use App\Livewire\Actions\Logout;
use App\Models\Company;
use App\Support\AppSwitcher;
use App\Support\Menu;
use App\Support\PortalApp;
use App\Support\WorkingYear;
use Livewire\Volt\Component;

new class extends Component
{
    /** @var list<array{key: string, label: string, url: string, accent: string}> */
    public array $apps = [];

    public string $currentApp = 'portal';

    public ?Company $company = null;

    public string $moduleLabel = '';

    public string $moduleUrl = '';

    public int $workingYear = 0;

    /** @var list<array{id: int, name: string}> */
    public array $companyOptions = [];

    /** @var list<array{label: string, url: string}> */
    public array $quickCreate = [];

    public string $initials = '';

    public function mount(): void
    {
        $company = request()->route('company');
        $this->company = $company instanceof Company ? $company : null;

        $app = PortalApp::fromHost(request()->getHost()) ?? PortalApp::PORTAL;
        $this->currentApp = $app->value;
        $this->apps = AppSwitcher::for(auth()->user(), $this->company);
        $this->moduleLabel = $app->label();

        if ($this->company) {
            $this->workingYear = WorkingYear::for($this->company);
            $this->moduleUrl = Menu::landingUrl(auth()->user(), $this->company, $app) ?? '';
            $this->quickCreate = Menu::quickCreate(auth()->user(), $this->company);
            $this->companyOptions = auth()->user()->visibleCompanies()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])
                ->all();
        }

        $this->initials = collect(preg_split('/\s+/u', trim(auth()->user()->name)))
            ->filter()->take(2)->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ appsOpen: false }" class="bg-white border-b border-sand">
    <div class="px-3 sm:px-6 lg:px-8">
        <div class="flex h-14 items-center gap-2 sm:gap-3">
            {{-- Opens the sidebar drawer. Hidden at lg and up, where the
                 sidebar is a permanent column. --}}
            <button type="button" @click="sidebarOpen = true"
                    aria-label="Отвори мени"
                    class="inline-flex items-center justify-center p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition lg:hidden">
                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            {{-- Каде си: модул · година горе, фирмата долу. Секогаш видливо, не
                 само на таблата. Кога човекот има повеќе фирми, името на фирмата
                 е и менувач — истото како „Фирма" во дното на левата лента. --}}
            @if ($company)
                <div class="relative hidden min-w-0 lg:block" x-data="{ open: false, filter: '' }" @keydown.escape.window="open = false">
                    <div class="flex items-center gap-1">
                        <div class="min-w-0 leading-tight">
                            <a href="{{ $moduleUrl ?: '#' }}" @if ($moduleUrl) wire:navigate @endif class="block text-xs text-stone hover:text-ink">
                                {{ $moduleLabel }} · {{ $workingYear }}
                            </a>
                            @if (count($companyOptions) > 1)
                                <button type="button" @click="open = ! open; $nextTick(() => $refs.filter && $refs.filter.focus())"
                                        class="inline-flex max-w-[22rem] items-center gap-1 text-sm font-semibold text-ink hover:text-brand">
                                    <span class="truncate">{{ $company->name }}</span>
                                    <svg class="h-3.5 w-3.5 shrink-0 text-stone" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" /></svg>
                                </button>
                            @else
                                <span class="block max-w-[22rem] truncate text-sm font-semibold text-ink">{{ $company->name }}</span>
                            @endif
                        </div>
                    </div>

                    @if (count($companyOptions) > 1)
                        <div x-show="open" x-cloak @click.outside="open = false" x-transition.opacity
                             class="absolute left-0 z-30 mt-2 w-80 rounded-xl border border-sand bg-white p-2 shadow-card">
                            @if (count($companyOptions) > 7)
                                <input x-ref="filter" x-model="filter" type="text" placeholder="Најди фирма…"
                                       class="mb-2 block w-full rounded-lg border-sand text-sm focus:border-brand focus:ring-brand">
                            @endif
                            <div class="max-h-72 overflow-y-auto">
                                @foreach ($companyOptions as $option)
                                    <a href="{{ route('companies.dashboard', $option['id']) }}"
                                       x-show="! filter || @js(mb_strtolower($option['name'])).includes(filter.toLowerCase())"
                                       class="block truncate rounded-lg px-3 py-2 text-sm hover:bg-orange-50 {{ $option['id'] === $company->id ? 'font-semibold text-brand' : 'text-ink' }}">
                                        {{ $option['name'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <div class="ms-auto flex items-center gap-1 sm:gap-2">
                <livewire:layout.global-search />

                @if ($quickCreate !== [])
                    <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                        <button type="button" @click="open = ! open" aria-label="Нова"
                                class="inline-flex h-9 items-center gap-1.5 rounded-full bg-brand px-3 text-sm font-medium text-white hover:opacity-90 sm:px-4 press">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" /></svg>
                            <span class="hidden sm:inline">Нова</span>
                        </button>
                        <div x-show="open" x-cloak @click.outside="open = false" x-transition.opacity
                             class="absolute right-0 z-30 mt-2 w-60 rounded-xl border border-sand bg-white py-1 shadow-card">
                            @foreach ($quickCreate as $action)
                                <a href="{{ $action['url'] }}" class="block px-4 py-2 text-sm text-ink hover:bg-orange-50">{{ $action['label'] }}</a>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($company)
                    <livewire:layout.bell />
                @endif

                @if ($apps !== [])
                    <button type="button" @click="appsOpen = true" aria-label="Апликации" title="Апликации"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-700 press">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path d="M3 3h4v4H3V3zm6 0h4v4H9V3zm6 0h2v4h-2V3zM3 9h4v4H3V9zm6 0h4v4H9V9zm6 0h2v4h-2V9zM3 15h4v2H3v-2zm6 0h4v2H9v-2zm6 0h2v2h-2v-2z" />
                        </svg>
                    </button>
                @endif

                {{-- Профилот е кругче со иницијали; името и е-поштата се во менито. --}}
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button type="button" aria-label="Профил"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-brand text-sm font-semibold text-white hover:opacity-90 focus:outline-none press">
                            {{ $initials }}
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="border-b border-sand px-4 py-2">
                            <div class="truncate text-sm font-semibold text-ink" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                            <div class="truncate text-xs text-stone">{{ auth()->user()->email }}</div>
                        </div>

                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            Профил
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                Одјави се
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </div>

    {{-- Панелот влегува од десно. Истите правила како мобилната фиока: Esc,
         клик надвор и копче го затвораат. --}}
    <div x-show="appsOpen" x-cloak @keydown.escape.window="appsOpen = false">
        <div x-transition.opacity @click="appsOpen = false" class="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-sm"></div>

        <div x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="fixed inset-y-0 right-0 z-50 w-80 bg-white border-l border-sand p-4 space-y-3">
            <div class="flex items-center justify-between pb-2 border-b border-sand">
                <span class="text-xs font-semibold tracking-wide text-gray-500">АПЛИКАЦИИ</span>
                <button type="button" @click="appsOpen = false" aria-label="Затвори"
                        class="p-2 -me-2 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition">
                    <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Описот и тонот се украс, па живеат тука. AppSwitcher останува
                 местото што одлучува КОЈ што гледа.

                 Променливата на јамката е $panelApp, не $app: @foreach ја
                 презапишува променливата и по јамката (Blade нема опсег на
                 јамка), па кратките имиња се избегнуваат по правило. --}}
            @php
                $appLook = [
                    'prodazba' => ['tone' => 'app-card--orange', 'text' => 'Фактури и кооперанти'],
                    'finansii' => ['tone' => 'app-card--green', 'text' => 'Книжење, извештаи и изводи'],
                    'plata' => ['tone' => 'app-card--indigo', 'text' => 'Вработени и пресметка на плата'],
                ];
            @endphp

            @foreach ($apps as $panelApp)
                @php $panelLook = $appLook[$panelApp['key']] ?? $appLook['prodazba']; @endphp
                <a href="{{ $panelApp['url'] }}"
                   class="app-card {{ $panelLook['tone'] }} {{ $panelApp['key'] === $currentApp ? 'is-current' : '' }} press"
                   style="--i: {{ $loop->index }}">
                    <span class="app-card__dot" aria-hidden="true"></span>
                    <span class="app-card__body">
                        <span class="app-card__label">{{ $panelApp['label'] }}</span>
                        <span class="app-card__text">{{ $panelLook['text'] }}</span>
                    </span>
                    @if ($panelApp['key'] === $currentApp)
                        <span class="app-card__here">тука си</span>
                    @endif
                </a>
            @endforeach

            {{-- companies.index е admin-екран (App\Livewire\CompanyIndex::mount()
                 враќа 403 за секој друг) — истото правило како копчето „Фирми“
                 во sidebar.blade.php. За секој друг корисник, патот назад е
                 таблата на фирмата во контекст — а ако нема фирма во контекст,
                 нема каде смислено да се прати, па линкот отсуствува. --}}
            @if (auth()->user()->hasRole('admin'))
                <a href="{{ route('clients.index') }}"
                   class="block px-3 py-2 mt-2 rounded-lg text-sm text-gray-600 border-t border-sand hover:bg-gray-50 press">
                    Портал — клиенти и поставки
                </a>
            @elseif ($company !== null)
                <a href="{{ route('companies.dashboard', $company) }}"
                   class="block px-3 py-2 mt-2 rounded-lg text-sm text-gray-600 border-t border-sand hover:bg-gray-50 press">
                    Портал — табла на фирмата
                </a>
            @endif
        </div>
    </div>
</nav>
