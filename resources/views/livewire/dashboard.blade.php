<div>
    <div class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6">
            <h1 class="text-xl font-bold text-gray-800 mb-1">Select a company</h1>
            <p class="text-sm text-gray-500 mb-4">Choose which company you want to work on.</p>

            @if ($companies->isEmpty())
                <p class="text-gray-500">You don't have access to any companies yet.</p>
            @else
                <ul class="divide-y divide-gray-200">
                    @foreach ($companies as $company)
                        <li>
                            <a href="{{ route('companies.dashboard', $company) }}" wire:navigate
                               class="block py-3 px-2 rounded-lg hover:bg-gray-50 font-medium text-gray-700">
                                {{ $company->name }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
