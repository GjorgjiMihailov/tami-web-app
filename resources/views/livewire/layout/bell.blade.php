<div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button type="button" @click="open = ! open" aria-label="Известувања"
            class="relative inline-flex h-9 w-9 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-700 press">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
        </svg>
        @if ($total > 0)
            <span data-bell-count class="absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-[#CF1C69] px-1 text-center text-[10px] font-semibold leading-[1.1rem] text-white">{{ $total > 99 ? '99+' : $total }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak @click.outside="open = false" x-transition.opacity
         class="absolute right-0 z-30 mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-xl border border-sand bg-white p-2 shadow-card">
        <p class="px-2 pb-1 pt-1 text-xs font-semibold tracking-wide text-stone">ШТО ЧЕКА НА ТЕБЕ</p>

        @forelse ($items as $item)
            <a href="{{ $item['url'] }}" class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm text-ink hover:bg-orange-50">
                <span>{{ $item['label'] }}</span>
                <span class="rounded-full bg-orange-100 px-2 text-xs font-semibold text-brand">{{ $item['count'] }}</span>
            </a>
        @empty
            <p class="px-2 py-3 text-sm text-stone">Нема ништо што чека. Сè е средено.</p>
        @endforelse
    </div>
</div>
