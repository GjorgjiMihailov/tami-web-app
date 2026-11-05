<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Support\InvoiceNumber;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class InvoiceSettings extends Component
{
    use WithFileUploads;

    public Company $company;

    /** Слика од потпис и од печат — се печатат над линијата на издавачот. */
    public $newSignature = null;

    public $newStamp = null;

    public bool $includeYear = true;

    public bool $yearFirst = true;

    public int $yearDigits = 4;

    /** Празен разделник се пренесува како 'none' — празна низа не поминува низ `in:`. */
    public string $separator = '/';

    public int $padding = 1;

    public string $prefix = '';

    /**
     * За фирма што доаѓа од друга програма: бројот од кој продолжуваат новите
     * фактури. Празно = без подлога. Текст, не int — празно поле во Livewire
     * не смее да се претвори во 0.
     */
    public string $nextNumber = '';

    /** Префикс на профактурата — годината, разделникот и должината се исти како кај фактурата. */
    public string $proformaPrefix = 'ПФ-';

    /** Префикс на испратницата — иста логика како профактурата. */
    public string $deliveryNotePrefix = 'ИСП-';

    public bool $saved = false;

    public bool $imagesSaved = false;

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
        $this->nextNumber = (string) ($company->invoice_number_next ?? '');
        $this->proformaPrefix = (string) ($company->proforma_number_prefix ?? '');
        $this->deliveryNotePrefix = (string) ($company->delivery_note_number_prefix ?? '');
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
            'nextNumber' => 'nullable|integer|min:1|max:999999',
            'proformaPrefix' => 'nullable|string|max:10',
            'deliveryNotePrefix' => 'nullable|string|max:10',
        ]);

        $this->company->update([
            'invoice_number_include_year' => $validated['includeYear'],
            'invoice_number_year_first' => $validated['yearFirst'],
            'invoice_number_year_digits' => $validated['yearDigits'],
            'invoice_number_separator' => $this->separatorValue(),
            'invoice_number_padding' => $validated['padding'],
            'invoice_number_prefix' => $validated['prefix'] !== '' ? $validated['prefix'] : null,
            'invoice_number_next' => filled($validated['nextNumber']) ? (int) $validated['nextNumber'] : null,
            'proforma_number_prefix' => $validated['proformaPrefix'] !== '' ? $validated['proformaPrefix'] : null,
            'delivery_note_number_prefix' => $validated['deliveryNotePrefix'] !== '' ? $validated['deliveryNotePrefix'] : null,
        ]);

        $this->saved = true;
    }

    /**
     * Качената слика се зачувува само ако е вистинска слика; старата датотека се
     * брише за да не остануваат сирачиња на дискот. Непополнето поле не се допира.
     */
    public function saveImages(): void
    {
        Gate::authorize('updateInvoiceSettings', $this->company);

        $this->validate([
            'newSignature' => 'nullable|image|mimes:png,jpg,jpeg|max:5120',
            'newStamp' => 'nullable|image|mimes:png,jpg,jpeg|max:5120',
        ], [
            'newSignature.image' => 'Потписот мора да биде слика (PNG или JPG).',
            'newSignature.mimes' => 'Потписот мора да биде PNG или JPG.',
            'newSignature.max' => 'Сликата на потписот не смее да биде поголема од 5 MB.',
            'newStamp.image' => 'Печатот мора да биде слика (PNG или JPG).',
            'newStamp.mimes' => 'Печатот мора да биде PNG или JPG.',
            'newStamp.max' => 'Сликата на печатот не смее да биде поголема од 5 MB.',
        ]);

        foreach (['signature' => $this->newSignature, 'stamp' => $this->newStamp] as $kind => $file) {
            if (! $file) {
                continue;
            }

            $column = $kind.'_path';
            $old = $this->company->{$column};
            $this->company->update([$column => $file->store('signatures/'.$this->company->id, 'public')]);

            if ($old) {
                Storage::disk('public')->delete($old);
            }
        }

        $this->reset('newSignature', 'newStamp');
        $this->imagesSaved = true;
    }

    public function removeSignature(): void
    {
        $this->removeImage('signature_path');
    }

    public function removeStamp(): void
    {
        $this->removeImage('stamp_path');
    }

    private function removeImage(string $column): void
    {
        Gate::authorize('updateInvoiceSettings', $this->company);

        $old = $this->company->{$column};
        $this->company->update([$column => null]);

        if ($old) {
            Storage::disk('public')->delete($old);
        }
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
            'deliveryNotePreview' => InvoiceNumber::format($this->previewCompany(), (int) now()->year, 1, $this->deliveryNotePrefix),
        ]);
    }
}
