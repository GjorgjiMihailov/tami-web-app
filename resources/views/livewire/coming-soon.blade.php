<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1 flex items-center gap-2">
        <span>{{ $featureLabel }}</span>
        <span class="text-xs font-medium text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">наскоро</span>
    </h1>
    <p class="text-sm text-gray-500 mb-4">{{ $company->name }}</p>

    <x-card>
        <p class="text-gray-700">{{ $featureSentence }}</p>
        <p class="text-sm text-gray-500 mt-3">
            Оваа страница сè уште не е изработена. Местото во менито е подготвено однапред,
            за да се знае каде ќе стои кога ќе биде готова.
        </p>
    </x-card>
</div>
