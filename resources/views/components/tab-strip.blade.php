@props(['tabs'])

<nav class="flex gap-1 border-b border-gray-200 mb-4">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['url'] }}" wire:navigate
           class="px-4 py-2 text-sm font-medium rounded-t-lg {{ $tab['active']
               ? 'bg-brand text-white'
               : 'text-gray-600 hover:bg-orange-50' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
