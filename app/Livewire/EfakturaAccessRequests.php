<?php

namespace App\Livewire;

use App\Models\Company;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EfakturaAccessRequests extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->check() && auth()->user()->hasRole('admin'), 403);
    }

    public function approve(Company $company): void
    {
        abort_unless(auth()->check() && auth()->user()->hasRole('admin'), 403);
        $company->update([
            'efaktura_firm_access_status' => Company::EFAKTURA_STATUS_APPROVED,
            'efaktura_firm_access_decided_by' => auth()->id(),
            'efaktura_firm_access_decided_at' => now(),
        ]);
    }

    public function reject(Company $company): void
    {
        abort_unless(auth()->check() && auth()->user()->hasRole('admin'), 403);
        $company->update([
            'efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REJECTED,
            'efaktura_firm_access_decided_by' => auth()->id(),
            'efaktura_firm_access_decided_at' => now(),
        ]);
    }

    public function render()
    {
        $pendingCompanies = Company::where('efaktura_firm_access_status', Company::EFAKTURA_STATUS_REQUESTED)
            ->orderBy('name')
            ->get();

        return view('livewire.efaktura-access-requests', ['pendingCompanies' => $pendingCompanies]);
    }
}
