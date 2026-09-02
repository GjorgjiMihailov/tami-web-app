<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">743 обрасци за внесување</h1>
    <p class="text-sm text-gray-500 mb-4">
        Обрасците подолу чекаат пријава во е-ПДД. Внесувањето се прави рачно на
        порталот на УЈП — овој список само кажува што останало и по кој ред.
    </p>

    <x-card>
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 bg-gray-50">
                    <th class="py-1">Клиент</th>
                    <th class="py-1">Датотека</th>
                    <th class="py-1">Прикачено</th>
                    <th class="py-1">Прикачил</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($forms as $form)
                    <tr class="hover:bg-orange-50">
                        <td class="py-1">{{ $form->company->name }}</td>
                        <td class="py-1">
                            @if ($form->documents->isNotEmpty())
                                <a href="{{ route('form743.download', [$form->company_id, $form]) }}" class="text-brand hover:underline">
                                    {{ $form->documents->first()->original_filename }}
                                </a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-1">{{ \App\Support\Format::date($form->created_at) }}</td>
                        <td class="py-1">{{ $form->uploader?->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-gray-500">Нема необработени обрасци.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</div>
