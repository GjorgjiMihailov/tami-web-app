<?php

use App\Livewire\Actions\Logout;
use App\Models\Company;
use App\Support\AppSwitcher;
use App\Support\PortalApp;
use Livewire\Volt\Component;

new class extends Component
{
    /** @var list<array{key: string, label: string, url: string, accent: string}> */
    public array $apps = [];

    public string $currentApp = 'portal';

    public ?Company $company = null;

    public function mount(): void
    {
        $company = request()->route('company');
        $this->company = $company instanceof Company ? $company : null;

        $this->currentApp = (PortalApp::fromHost(request()->getHost()) ?? PortalApp::PORTAL)->value;
        $this->apps = AppSwitcher::for(auth()->user(), $this->company);
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

<nav x-data="{ open: false, appsOpen: false }" class="bg-white border-b border-sand">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex justify-end h-14 items-center">
            {{-- Opens the sidebar drawer. Hidden at lg and up, where the
                 sidebar is a permanent column. me-auto keeps it on the left
                 without disturbing the desktop layout, since display:none
                 takes it out of the flex row entirely. --}}
            <button type="button" @click="sidebarOpen = true"
                    aria-label="Отвори мени"
                    class="me-auto inline-flex items-center justify-center p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition lg:hidden">
                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            @if ($apps !== [])
                <button type="button" @click="appsOpen = true"
                        class="inline-flex items-center gap-2 px-3 py-2 me-2 text-xs font-semibold tracking-wide text-gray-600 rounded-lg hover:bg-gray-100 press">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M3 3h4v4H3V3zm6 0h4v4H9V3zm6 0h2v4h-2V3zM3 9h4v4H3V9zm6 0h4v4H9V9zm6 0h2v4h-2V9zM3 15h4v2H3v-2zm6 0h4v2H9v-2zm6 0h2v2h-2v-2z" />
                    </svg>
                    АПЛИКАЦИИ
                </button>
            @endif

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-full text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
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

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-4 pb-1 border-t border-sand">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-gray-500">{{ auth()->user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    Профил
                </x-responsive-nav-link>

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        Одјави се
                    </x-responsive-nav-link>
                </button>
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
                <a href="{{ route('companies.index') }}"
                   class="block px-3 py-2 mt-2 rounded-lg text-sm text-gray-600 border-t border-sand hover:bg-gray-50 press">
                    Портал — фирми и поставки
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
