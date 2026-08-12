<?php

namespace App\Livewire;

use App\Models\Company;
use App\Support\Menu;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One shared page behind every "наскоро" menu entry.
 *
 * Deliberately a real page rather than a disabled menu entry: a disabled
 * control reads as broken and says nothing, while a page can state what the
 * feature will do. For the admin these entries are the remaining-work map.
 */
#[Layout('layouts.app')]
class ComingSoon extends Component
{
    public Company $company;

    public string $feature;

    public string $featureLabel = '';

    public string $featureSentence = '';

    public function mount(Company $company, string $feature): void
    {
        Gate::authorize('view', $company);

        // наскоро entries are visible to admin and accountant only, so the
        // page they lead to must refuse a client too — hiding the link is
        // not access control.
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(array_key_exists($feature, Menu::SOON_FEATURES), 404);

        $this->company = $company;
        $this->feature = $feature;
        $this->featureLabel = Menu::SOON_FEATURES[$feature]['label'];
        $this->featureSentence = Menu::SOON_FEATURES[$feature]['sentence'];
    }

    public function render()
    {
        return view('livewire.coming-soon');
    }
}
