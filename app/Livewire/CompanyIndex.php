<?php

namespace App\Livewire;

use App\Models\Company;
use Livewire\Component;

class CompanyIndex extends Component
{
    public function render()
    {
        $user = auth()->user();

        $companies = match (true) {
            $user->hasRole('admin') => Company::orderBy('name')->get(),
            $user->hasRole('client') => Company::where('id', $user->company_id)->get(),
            $user->hasRole('accountant') => $user->assignedCompanies()->orderBy('name')->get(),
            default => collect(),
        };

        return view('livewire.company-index', ['companies' => $companies]);
    }
}
