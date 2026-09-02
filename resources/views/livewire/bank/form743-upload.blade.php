<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">743 обрасци — {{ $company->name }}</h1>

    <x-card class="mb-6">
        <h2 class="font-semibold text-gray-700 mb-2">Прикачи образец</h2>
        <p class="text-sm text-gray-500 mb-3">
            Прикачете го образецот 743 што ви го дала банката. Ништо не треба да
            пополнувате — податоците ги презема канцеларијата од самиот образец.
        </p>
        <form wire:submit="upload" class="flex flex-wrap gap-3 items-end">
            <div>
                <x-input-label for="newFile" value="Датотека" />
                <input type="file" id="newFile" wire:model="newFile" class="text-sm">
                @error('newFile') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <x-primary-button type="submit">Прикачи</x-primary-button>
        </form>
    </x-card>

    <x-card>
        <h2 class="font-semibold text-gray-700 mb-2">Прикачени обрасци</h2>
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 bg-gray-50">
                    <th class="py-1">Датотека</th>
                    <th class="py-1">Прикачено</th>
                    <th class="py-1">Прикачил</th>
                    <th class="py-1">Состојба</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($forms as $form)
                    <tr class="hover:bg-orange-50">
                        <td class="py-1">
                            @if ($form->documents->isNotEmpty())
                                <a href="{{ route('form743.download', [$company, $form]) }}" class="text-brand hover:underline">
                                    {{ $form->documents->first()->original_filename }}
                                </a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="py-1">{{ \App\Support\Format::date($form->created_at) }}</td>
                        <td class="py-1">{{ $form->uploader?->name }}</td>
                        <td class="py-1">
                            <span @class([
                                'text-xs px-2 py-0.5 rounded-full',
                                'bg-amber-100 text-amber-800' => $form->status->isPending(),
                                'bg-green-100 text-green-800' => $form->status->isFiled(),
                            ])>{{ $form->status->label() }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-gray-500">Сè уште нема прикачени обрасци.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
</div>
