<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Support\InvoiceNumber;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class InvoiceSettings extends Component
{
    public Company $company;

    public bool $includeYear = true;

    public bool $yearFirst = true;

    public int $yearDigits = 4;

    /** Празен разделник се пренесува како 'none' — празна низа не поминува низ `in:`. */
    public string $separator = '/';

    public int $padding = 1;

    public string $prefix = '';

    /** Префикс на профактурата — годината, разделникот и должината се исти како кај фактурата. */
    public string $proformaPrefix = 'ПФ-';

    public bool $saved = false;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
        $this->includeYear = (bool) $company->invoice_number_include_year;
        $this->yearFirst = (bool) $company->invoice_number_year_first;
        $this->yearDigits = (int) $company->invoice_number_year_digits;
        $this->separator = $company->invoice_number_separator === '' ? 'none' : (string) $company->invoice_number_separator;
        $this->padding = (int) $company->invoice_number_padding;
        $this->prefix = (string) ($company->invoice_number_prefix ?? '');
        $this->proformaPrefix = (string) ($company->proforma_number_prefix ?? '');
    }

    public function save(): void
    {
        Gate::authorize('updateInvoiceSettings', $this->company);

        $validated = $this->validate([
            'includeYear' => 'boolean',
            'yearFirst' => 'boolean',
            'yearDigits' => 'required|integer|in:2,4',
            'separator' => 'required|string|in:/,-,.,none',
            'padding' => 'required|integer|min:1|max:6',
            'prefix' => 'nullable|string|max:10',
            'proformaPrefix' => 'nullable|string|max:10',
        ]);

        $this->company->update([
            'invoice_number_include_year' => $validated['includeYear'],
            'invoice_number_year_first' => $validated['yearFirst'],
            'invoice_number_year_digits' => $validated['yearDigits'],
            'invoice_number_separator' => $this->separatorValue(),
            'invoice_number_padding' => $validated['padding'],
            'invoice_number_prefix' => $validated['prefix'] !== '' ? $validated['prefix'] : null,
            'proforma_number_prefix' => $validated['proformaPrefix'] !== '' ? $validated['proformaPrefix'] : null,
        ]);

        $this->saved = true;
    }

    private function separatorValue(): string
    {
        return $this->separator === 'none' ? '' : $this->separator;
    }

    /**
     * Прегледот се гради врз незачувана фирма и минува низ истата класа што
     * доделува вистински броеви. Второ, паралелно пресметување би можело да
     * покажува едно, а фактурата да носи друго.
     */
    private function previewCompany(): Company
    {
        $company = new Company;
        $company->invoice_number_prefix = $this->prefix !== '' ? $this->prefix : null;
        $company->invoice_number_include_year = $this->includeYear;
        $company->invoice_number_year_first = $this->yearFirst;
        $company->invoice_number_year_digits = $this->yearDigits;
        $company->invoice_number_separator = $this->separatorValue();
        $company->invoice_number_padding = $this->padding;

        return $company;
    }

    public function render()
    {
        return view('livewire.invoicing.invoice-settings', [
            'preview' => InvoiceNumber::format($this->previewCompany(), (int) now()->year, 1),
            'proformaPreview' => InvoiceNumber::format($this->previewCompany(), (int) now()->year, 1, $this->proformaPrefix),
        ]);
    }
}
