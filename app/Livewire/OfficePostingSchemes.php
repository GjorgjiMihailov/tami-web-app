<?php

namespace App\Livewire;

use App\Services\Posting\PostingSchemeSets;
use App\Support\Posting\PostingDocType;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Личниот предложен комплет шеми на сметководителот. Не се уредува овде —
 * комплетот се прави во вистинска фирма (со „Пробај“) и таму се поставува како
 * личен предлог; овде само се гледа и се враќа на стандарден.
 */
#[Layout('layouts.app')]
class OfficePostingSchemes extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
    }

    public function forgetSet(string $type): void
    {
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        $docType = PostingDocType::tryFrom($type) ?? abort(404);

        PostingSchemeSets::forget(auth()->user(), $docType);
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.office-posting-schemes', [
            'entries' => collect(PostingDocType::cases())->map(fn (PostingDocType $type) => [
                'type' => $type,
                'mine' => PostingSchemeSets::has($user, $type),
            ]),
        ]);
    }
}
