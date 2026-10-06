<?php

namespace App\Livewire\Accounting;

use App\Models\Company;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PostingSchemeIndex extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('update', $company);
        $this->company = $company;
    }

    public function render()
    {
        // Шемите се создаваат лено при прво книжење; овде ги материјализираме
        // за да може сметководителот да ги види и пред тоа.
        $schemes = collect(PostingDocType::cases())->map(fn (PostingDocType $type) => [
            'type' => $type,
            'scheme' => PostingSchemes::for($this->company, $type)->loadCount('rows'),
        ]);

        return view('livewire.accounting.posting-scheme-index', ['schemes' => $schemes]);
    }
}
