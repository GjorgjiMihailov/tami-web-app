<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DocumentIndex extends Component
{
    public Company $company;

    public string $categoryFilter = '';

    public string $typeFilter = '';

    public string $fromDate = '';

    public string $toDate = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
    }

    public function render()
    {
        $query = Document::where('company_id', $this->company->id)->with(['documentable', 'uploader'])->latest();

        if ($this->categoryFilter !== '') {
            $query->where('category', $this->categoryFilter);
        }

        if ($this->typeFilter !== '') {
            $query->where('documentable_type', $this->typeFilter);
        }

        if ($this->fromDate !== '') {
            $query->whereDate('created_at', '>=', $this->fromDate);
        }

        if ($this->toDate !== '') {
            $query->whereDate('created_at', '<=', $this->toDate);
        }

        return view('livewire.document-index', [
            'documents' => $query->get(),
            'categories' => Document::CATEGORIES,
            'types' => [
                'purchase_invoice' => 'Purchase Invoice',
                'sales_invoice' => 'Sales Invoice',
                'journal_entry' => 'Journal Entry',
                'partner' => 'Partner',
            ],
        ]);
    }
}
