<?php

namespace App\Livewire;

use App\Models\Company;
use App\Support\CurrentCompany;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Почетна for the admin; a router for everyone else.
 *
 * Non-admins have no Почетна and no Фирми in their menu, but login still
 * redirects here (see resources/views/livewire/pages/auth/login.blade.php).
 * Deciding the destination here keeps that rule in one place instead of
 * spreading it across the four Volt auth pages that all redirect to
 * 'dashboard'.
 */
#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function mount()
    {
        $user = auth()->user();

        if ($user->hasRole('admin')) {
            return null;
        }

        $target = $this->companyToOpen($user);

        // No target means an accountant with several companies and nothing
        // remembered — fall through and render the choice screen below.
        return $target ? $this->redirect(route('companies.dashboard', $target)) : null;
    }

    private function companyToOpen($user): ?Company
    {
        $visible = $user->visibleCompanies();

        $rememberedId = CurrentCompany::lastFor($user);

        if ($rememberedId !== null) {
            $remembered = (clone $visible)->whereKey($rememberedId)->first();

            if ($remembered) {
                return $remembered;
            }
        }

        $companies = (clone $visible)->orderBy('name')->get();

        return $companies->count() === 1 ? $companies->first() : null;
    }

    public function render()
    {
        $companies = auth()->user()->visibleCompanies()->orderBy('name')->get();

        return view('livewire.dashboard', ['companies' => $companies]);
    }
}
