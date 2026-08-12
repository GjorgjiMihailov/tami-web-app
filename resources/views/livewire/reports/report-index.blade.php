<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Извештаи и обрасци — {{ $company->name }}</h1>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($available as $report)
            <a href="{{ $report['url'] }}" wire:navigate
               class="block bg-white rounded-2xl shadow-card p-4 hover:bg-orange-50 transition">
                <span class="font-semibold text-gray-800">{{ $report['label'] }}</span>
                <p class="text-sm text-gray-500 mt-1">{{ $report['description'] }}</p>
            </a>
        @endforeach

        @foreach ($soon as $report)
            <div class="block bg-white rounded-2xl shadow-card p-4 opacity-60">
                <span class="font-semibold text-gray-500 flex items-center gap-2">
                    {{ $report['label'] }}
                    <span class="text-xs font-medium text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">наскоро</span>
                </span>
                <p class="text-sm text-gray-500 mt-1">{{ $report['description'] }}</p>
            </div>
        @endforeach
    </div>
</div>
