<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Companies</h1>

    @if ($companies->isEmpty())
        <p class="text-gray-500">No companies to show.</p>
    @else
        <ul class="divide-y divide-gray-200">
            @foreach ($companies as $company)
                <li class="py-2 flex items-center justify-between">
                    <span>{{ $company->name }}</span>
                    <span class="space-x-3 text-sm">
                        <a href="{{ route('accounting.accounts.index', $company) }}" class="text-indigo-600 hover:underline">Accounts</a>
                        <a href="{{ route('accounting.journal-entries.index', $company) }}" class="text-indigo-600 hover:underline">Journal</a>
                        <a href="{{ route('accounting.reports.ledger-card', $company) }}" class="text-indigo-600 hover:underline">Ledger Card</a>
                        <a href="{{ route('accounting.reports.trial-balance', $company) }}" class="text-indigo-600 hover:underline">Trial Balance</a>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
