<div>
    <h1 class="text-lg font-bold text-ink">{{ $company->name }}</h1>
    <p class="mt-1 text-sm text-stone">Продажба · работна година {{ $workingYear }}</p>

    {{-- Долго име на променливата намерно: @foreach ја презапишува променливата
         и по јамката, Blade нема опсег на јамка. --}}
    <div class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
        @foreach ($this->links() as $boardLink)
            <a href="{{ $boardLink['url'] }}" wire:navigate
               class="board-link {{ $boardLink['tone'] }} press"
               style="--i: {{ $loop->index }}">
                <span class="board-link__icon" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $boardLink['icon'] }}" />
                    </svg>
                </span>
                <span class="board-link__label">{{ $boardLink['label'] }}</span>
                <svg class="board-link__arrow h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12" />
                </svg>
            </a>
        @endforeach
    </div>
</div>
