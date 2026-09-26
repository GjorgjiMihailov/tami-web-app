<div class="w-60 shrink-0 bg-rail border-r border-rail-line text-rail-text flex flex-col min-h-screen app-sidebar"
     :class="{ 'is-open': sidebarOpen }">
    <div class="px-4 py-4 border-b border-rail-line flex items-center justify-between gap-2">
        {{-- Двата реда се два одделни јазли намерно: Livewire му врзува
             коментари-маркери на текст што е сечен со @if, па заедничка линија
             со услов среде неа би се распаднала при освежување. --}}
        <a href="{{ $brandUrl }}" @if ($this->app() === \App\Support\PortalApp::PORTAL) wire:navigate @endif class="block leading-tight">
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
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ $currentRoute === 'dashboard' ? 'bg-brand text-white press' : 'text-rail-text hover:bg-rail-soft press' }}">
                    Дома
                </a>
                <a href="{{ route('clients.index') }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ str_starts_with($currentRoute, 'clients.') ? 'bg-brand text-white press' : 'text-rail-text hover:bg-rail-soft press' }}">
                    Клиенти
                </a>
                <a href="{{ route('profile') }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ in_array($currentRoute, ['profile', 'efaktura.access-requests'], true) ? 'bg-brand text-white press' : 'text-rail-text hover:bg-rail-soft press' }}">
                    Поставки
                </a>
            @elseif (auth()->check() && auth()->user()->hasRole('accountant'))
                <a href="{{ route('companies.index') }}" wire:navigate
                   class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 {{ $currentRoute === 'companies.index' ? 'bg-brand text-white press' : 'text-rail-text hover:bg-rail-soft press' }}">
                    Клиенти
                </a>
            @endif
        @endif

        @if ($company)
            <div class="pt-1">
                {{-- Таблата стои над групите зашто не е ставка од ниту една
                     група — таа е влезот во целата апликација. Истиот облик
                     како самостојната врска „Документи" на дното. --}}
                {{-- Класата sidebar-board не е украс: панелот АПЛИКАЦИИ ја носи
                     истата адреса на СЕКОЈА страна, па тест што само би барал
                     дали URL-от го има во HTML-от не може да ги разликува
                     двете. --}}
                @if ($boardUrl !== '')
                    <a href="{{ $boardUrl }}" wire:navigate
                       class="sidebar-board block px-4 py-2 mb-1 text-sm font-medium rounded-lg mx-3 {{ $currentRoute === 'prodazba.dashboard' ? 'bg-brand text-white press' : 'text-rail-text hover:bg-rail-soft press' }}">
                        Дома
                    </a>
                @endif

                {{-- Отворањето живее во прелистувачот: секој клик на „+" порано
                     беше цело барање до серверот, па каква и да е анимацијата
                     врз тоа, се гледаше како трепкање. Серверот и понатаму
                     одредува која група е отворена при влегување
                     ($expandedGroup), а знакот „−/+" го пишува Alpine — во
                     Blade би останал заглавен на почетната вредност. --}}
                @foreach ($menu as $group)
                    {{-- data-group е тука за да може да се провери КОЈА група
                         е отворена: откако лизгањето е во прелистувачот, сите
                         групи се во HTML (скриени), па отсуството на врска
                         повеќе не значи затворена група. --}}
                    {{-- Класата is-open ја става Alpine (и Blade за првото
                         исцртување) — CSS-от во app.css по неа ја врти стрелката
                         и ги внесува ставките една по една. --}}
                    <div data-group="{{ $group['key'] }}" x-data="{ open: @js($expandedGroup === $group['key']) }"
                         class="menu-group {{ $expandedGroup === $group['key'] ? 'is-open' : '' }}" :class="{ 'is-open': open }">
                        <button type="button" @click="open = ! open" :aria-expanded="open"
                                class="menu-group__head w-full text-left flex items-center justify-between px-4 py-2 text-sm font-medium rounded-lg mx-3 text-rail-muted hover:bg-rail-soft hover:text-rail-text"
                                style="width: calc(100% - 1.5rem);">
                            <span>{{ $group['label'] }}</span>
                            <svg class="menu-group__chevron h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                        {{-- x-cloak само на затворените групи: без него, сè до
                             вклучувањето на Alpine сите групи се исцртани
                             отворени и менито трепка. Отворената група го нема,
                             за да се види веднаш. --}}
                        <div x-show="open" @unless ($expandedGroup === $group['key']) x-cloak @endunless x-collapse.duration.320ms class="menu-group__items ml-7 border-l border-rail-line">
                            @foreach ($group['items'] as $item)
                                <a href="{{ $item['url'] }}" wire:navigate style="--i: {{ $loop->index }}"
                                   class="menu-item flex items-center gap-2 px-4 py-1.5 text-sm {{ $this->isActive($item['pattern']) ? 'is-active text-brand font-medium' : ($item['soon'] ? 'text-rail-muted hover:text-rail-text' : 'text-rail-text hover:text-white') }}">
                                    <span>{{ $item['label'] }}</span>
                                    @if ($item['soon'])
                                        <span class="text-[10px] uppercase tracking-wide text-rail-muted">наскоро</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if (! $company->type->isIndividual() && $this->app() === \App\Support\PortalApp::PRODAZBA)
                    <a href="{{ route('documents.index', $company) }}" wire:navigate
                       class="block px-4 py-2 text-sm font-medium rounded-lg mx-3 mt-1 {{ str_starts_with($currentRoute, 'documents.') ? 'bg-brand text-white press' : 'text-rail-text hover:bg-rail-soft press' }}">
                        Документи
                    </a>
                @endif
            </div>
        @endif
    </nav>

    @if ($company)
        {{-- Фирма и година живеат најдолу: менито е прво, а изборот се менува ретко. --}}
        <div class="border-t border-rail-line pt-3">
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

        </div>
    @endif
</div>
