<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Companies</h1>

    @if ($companies->isEmpty())
        <p class="text-gray-500">No companies to show.</p>
    @else
        <ul class="divide-y divide-gray-200">
            @foreach ($companies as $company)
                <li class="py-2">{{ $company->name }}</li>
            @endforeach
        </ul>
    @endif
</div>
