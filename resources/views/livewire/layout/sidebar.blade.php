<div class="w-60 shrink-0 bg-rail border-r border-rail-line text-rail-text flex flex-col min-h-screen app-sidebar"
     :class="{ 'is-open': sidebarOpen }">
    <div class="px-4 py-4 border-b border-rail-line flex items-center justify-between gap-2">
        {{-- Двата реда се два одделни јазли намерно: Livewire му врзува
             коментари-маркери на текст што е сечен со @if, па заедничка линија
             со услов среде неа би се распаднала при освежување. --}}
        <a href="{{ $brandUrl }}" wire:navigate class="block leading-tight">
            <span class="block font-bold text-sm {{ $this->app()->accent() }}">{{ $this->app()->sidebarName() }}</span>
            <span class="block italic text-[11px] text-rail-muted">{{ $this->app()->sidebarTagline() }}</span>
        </a>
        <button type="button" @click="sidebarOpen = false"
                aria-label="Затвори мени"
                class="-me-2 p-2 rounded-md text-rail-muted hover:text-rail-text hover:bg-rail-soft transition lg:hidden">
            <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <nav class="flex-1 py-3 space-y-1">
        {{-- Трите врски долу водат на портални рути кои не постојат на
             апликациски субдомен — таму би биле врски кон никаде. --}}
        @if ($this->app() === \App\Support\PortalApp::PORTAL)
            @if (auth()->check() && auth()->user()->hasRole('admin'))
                <a href="{{ route('dashboard') }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ $currentRoute === 'dashboard' ? 'bg-brand text-white' : 'text-rail-text hover:bg-rail-soft' }}">
                    Почетна
                </a>
                <a href="{{ route('companies.index') }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ $currentRoute === 'companies.index' ? 'bg-brand text-white' : 'text-rail-text hover:bg-rail-soft' }}">
                    Фирми
                </a>
            @endif

            {{-- Работниот список ги собира обрасците од сите клиенти, па стои тука
                 горе со глобалните врски, а не во менито на една фирма. --}}
            @if (auth()->check() && auth()->user()->hasAnyRole(['admin', 'accountant']))
                <a href="{{ route('form743.worklist') }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ $currentRoute === 'form743.worklist' ? 'bg-brand text-white' : 'text-rail-text hover:bg-rail-soft' }}">
                    743 обрасци
                </a>
            @endif
        @endif

        @if ($company)
            <div class="pt-4 mt-3 border-t border-rail-line">
                {{-- Both selectors stack their label above the control. A
                     <select> is as wide as its longest <option>, and real
                     company names ("ФАЈНЕНС БАДИ ДООЕЛ СКОПЈЕ") are far wider
                     than the 240px rail — so it needs the full width, plus
                     min-w-0 to defeat the default min-width:auto that stops a
                     flex/grid child from shrinking below its content. --}}
                <div class="px-4 pb-3 space-y-2">
                    <label class="block text-xs text-rail-muted">
                        <span class="block mb-1">Фирма</span>
                        {{-- Менувањето фирма секогаш се враќа на порталната табла.
                             Новата фирма може да ги нема истите модули, па почетниот
                             екран на тековната апликација за неа може и да не
                             постои — таблата е единствената адреса што сигурно
                             постои за секоја фирма. --}}
                        <select onchange="if (this.value) window.location.href = this.value"
                                class="block w-full min-w-0 truncate rounded-lg bg-rail-soft border-rail-line text-sm py-1 text-rail-text focus:border-brand focus:ring-brand">
                            @foreach ($companyOptions as $option)
                                <option value="{{ route('companies.dashboard', $option['id']) }}"
                                        @selected($option['id'] === $company->id)>{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-xs text-rail-muted">
                        <span class="block mb-1">Година</span>
                        <select wire:model.live="workingYear"
                                class="block w-full min-w-0 rounded-lg bg-rail-soft border-rail-line text-sm py-1 text-rail-text focus:border-brand focus:ring-brand">
                            @foreach ($availableYears as $year)
                                <option value="{{ $year }}">{{ $year }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                @foreach ($menu as $group)
                    <button type="button" wire:click="toggleGroup('{{ $group['key'] }}')"
                            class="w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium rounded-lg mx-3 text-rail-muted hover:bg-rail-soft"
                            style="width: calc(100% - 1.5rem);">
                        <span>{{ $group['label'] }}</span>
                        <span>{{ $expandedGroup === $group['key'] ? '−' : '+' }}</span>
                    </button>
                    @if ($expandedGroup === $group['key'])
                        <div class="pl-6">
                            @foreach ($group['items'] as $item)
                                <a href="{{ $item['url'] }}" wire:navigate
                                   class="flex items-center gap-2 px-4 py-1.5 text-sm {{ $this->isActive($item['pattern']) ? 'text-brand font-medium' : ($item['soon'] ? 'text-rail-muted hover:text-rail-text' : 'text-rail-text hover:text-white') }}">
                                    <span>{{ $item['label'] }}</span>
                                    @if ($item['soon'])
                                        <span class="text-[10px] uppercase tracking-wide text-rail-muted">наскоро</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endforeach

                @if (! $company->type->isIndividual() && $this->app() === \App\Support\PortalApp::PRODAZBA)
                    <a href="{{ route('documents.index', $company) }}" wire:navigate
                       class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 mt-1 {{ str_starts_with($currentRoute, 'documents.') ? 'bg-brand text-white' : 'text-rail-text hover:bg-rail-soft' }}">
                        Документи
                    </a>
                @endif
            </div>
        @endif
    </nav>
</div>
