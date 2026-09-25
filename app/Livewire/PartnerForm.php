<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PartnerForm extends Component
{
    public Company $company;

    public ?Partner $partner = null;

    // Горниот дел
    public string $type = 'legal_entity';

    public string $contactSalutation = '';

    public string $contactFirstName = '';

    public string $contactLastName = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $mobile = '';

    public string $invoiceLanguage = 'mk';

    // Други податоци
    public string $taxId = '';

    public string $registrationNumber = '';

    public string $directorName = '';

    public bool $isVatRegistered = false;

    public string $vatNumber = '';

    public string $paymentTermsDays = '';

    public array $bankAccounts = [];

    // Адреса — за фактурирање
    public string $country = '';

    public string $streetAddress = '';

    public string $streetNumber = '';

    public string $postalCode = '';

    public string $city = '';

    public string $address = '';

    // Адреса — за испорака
    public string $shippingCountry = '';

    public string $shippingStreetAddress = '';

    public string $shippingStreetNumber = '';

    public string $shippingPostalCode = '';

    public string $shippingCity = '';

    // Контакт лица
    public array $contacts = [];

    public function mount(Company $company, ?Partner $partner = null): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;

        if ($partner === null) {
            Gate::authorize('create', Partner::class);
            $this->bankAccounts = [$this->blankBank()];

            return;
        }

        Gate::authorize('update', $partner);

        // URL-от носи две независни id-ња — кооперантот мора да е на оваа фирма.
        if ($partner->company_id !== $company->id) {
            abort(404);
        }

        $this->partner = $partner;
        $this->type = $partner->type;
        $this->contactSalutation = (string) $partner->contact_salutation;
        $this->contactFirstName = (string) $partner->contact_first_name;
        $this->contactLastName = (string) $partner->contact_last_name;
        $this->name = $partner->name;
        $this->email = (string) $partner->email;
        $this->phone = (string) $partner->phone;
        $this->mobile = (string) $partner->mobile;
        $this->invoiceLanguage = $partner->invoice_language->value;
        $this->taxId = (string) $partner->tax_id;
        $this->registrationNumber = (string) $partner->registration_number;
        $this->directorName = (string) $partner->director_name;
        $this->isVatRegistered = $partner->is_vat_registered;
        $this->vatNumber = (string) $partner->vat_number;
        $this->paymentTermsDays = $partner->payment_terms_days === null ? '' : (string) $partner->payment_terms_days;
        $this->country = (string) $partner->country;
        $this->streetAddress = (string) $partner->street_address;
        $this->streetNumber = (string) $partner->street_number;
        $this->postalCode = (string) $partner->postal_code;
        $this->city = (string) $partner->city;
        $this->address = (string) $partner->address;
        $this->shippingCountry = (string) $partner->shipping_country;
        $this->shippingStreetAddress = (string) $partner->shipping_street_address;
        $this->shippingStreetNumber = (string) $partner->shipping_street_number;
        $this->shippingPostalCode = (string) $partner->shipping_postal_code;
        $this->shippingCity = (string) $partner->shipping_city;

        $banks = $partner->bankAccounts()->get();
        $this->bankAccounts = $banks->isEmpty()
            ? [$this->blankBank()]
            : $banks->map(fn ($row) => [
                'bank_name' => (string) $row->bank_name,
                'account_number' => (string) $row->account_number,
            ])->all();

        $this->contacts = $partner->contacts()->get()->map(fn ($row) => [
            'salutation' => (string) $row->salutation,
            'first_name' => (string) $row->first_name,
            'last_name' => (string) $row->last_name,
            'email' => (string) $row->email,
            'phone' => (string) $row->phone,
            'mobile' => (string) $row->mobile,
        ])->all();
    }

    private function blankBank(): array
    {
        return ['bank_name' => '', 'account_number' => ''];
    }

    private function blankContact(): array
    {
        return ['salutation' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'mobile' => ''];
    }

    public function addContact(): void
    {
        if (count($this->contacts) < 10) {
            $this->contacts[] = $this->blankContact();
        }
    }

    public function removeContact(int $index): void
    {
        unset($this->contacts[$index]);
        $this->contacts = array_values($this->contacts);
    }

    public function copyBillingToShipping(): void
    {
        $this->shippingCountry = $this->country;
        $this->shippingStreetAddress = $this->streetAddress;
        $this->shippingStreetNumber = $this->streetNumber;
        $this->shippingPostalCode = $this->postalCode;
        $this->shippingCity = $this->city;
    }

    public function updated(string $name, $value): void
    {
        // Кога ќе се пополни последниот ред за сметка, се отвора нов (најмногу пет).
        if (str_starts_with($name, 'bankAccounts.') && str_ends_with($name, '.account_number')) {
            $lastIndex = array_key_last($this->bankAccounts);
            $currentIndex = (int) explode('.', $name)[1];

            if ($currentIndex === $lastIndex && trim((string) $value) !== '' && count($this->bankAccounts) < 5) {
                $this->bankAccounts[] = $this->blankBank();
            }
        }
    }

    /** Предлози за „Назив": фирмата, па името и презимето во двата реда. */
    public function nameSuggestions(): array
    {
        $first = trim($this->contactFirstName);
        $last = trim($this->contactLastName);

        return array_values(array_filter(array_unique([
            trim("{$first} {$last}"),
            trim("{$last} {$first}"),
        ])));
    }

    public function save()
    {
        if ($this->partner) {
            Gate::authorize('update', $this->partner);
        } else {
            Gate::authorize('create', Partner::class);
        }

        $validated = $this->validate([
            'type' => ['required', Rule::in(['individual', 'legal_entity'])],
            'contactSalutation' => 'nullable|string|max:20',
            'contactFirstName' => 'nullable|string|max:100',
            'contactLastName' => 'nullable|string|max:100',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'invoiceLanguage' => ['required', Rule::in(['mk', 'en'])],
            'taxId' => 'nullable|string|max:255',
            'registrationNumber' => 'nullable|string|max:255',
            'directorName' => 'nullable|string|max:255',
            'isVatRegistered' => 'boolean',
            'vatNumber' => 'nullable|string|max:255',
            'paymentTermsDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'country' => 'nullable|string|max:255',
            'streetAddress' => 'nullable|string|max:255',
            'streetNumber' => 'nullable|string|max:50',
            'postalCode' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'shippingCountry' => 'nullable|string|max:255',
            'shippingStreetAddress' => 'nullable|string|max:255',
            'shippingStreetNumber' => 'nullable|string|max:50',
            'shippingPostalCode' => 'nullable|string|max:20',
            'shippingCity' => 'nullable|string|max:255',
            'bankAccounts' => 'array|max:5',
            'bankAccounts.*.bank_name' => 'nullable|string|max:255',
            'bankAccounts.*.account_number' => 'nullable|string|max:255',
            'contacts' => 'array|max:10',
            'contacts.*.salutation' => 'nullable|string|max:20',
            'contacts.*.first_name' => 'nullable|string|max:100',
            'contacts.*.last_name' => 'nullable|string|max:100',
            'contacts.*.email' => 'nullable|email|max:255',
            'contacts.*.phone' => 'nullable|string|max:50',
            'contacts.*.mobile' => 'nullable|string|max:50',
        ]);

        $isLegalEntity = $validated['type'] === 'legal_entity';
        $isVatRegistered = $isLegalEntity && $validated['isVatRegistered'];

        // Англиска фактура важи само за физичко лице. Скриено поле во Blade не
        // е заклучување — тоа се прави овде.
        $invoiceLanguage = $this->company->type->isIndividual() ? $validated['invoiceLanguage'] : 'mk';

        $attributes = [
            'name' => $validated['name'],
            'type' => $validated['type'],
            'contact_salutation' => $validated['contactSalutation'] ?: null,
            'contact_first_name' => $validated['contactFirstName'] ?: null,
            'contact_last_name' => $validated['contactLastName'] ?: null,
            'tax_id' => $validated['taxId'] ?: null,
            'registration_number' => $isLegalEntity ? ($validated['registrationNumber'] ?: null) : null,
            'director_name' => $isLegalEntity ? ($validated['directorName'] ?: null) : null,
            'is_vat_registered' => $isVatRegistered,
            'vat_number' => $isVatRegistered ? ($validated['vatNumber'] ?: null) : null,
            'email' => $validated['email'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'mobile' => $validated['mobile'] ?: null,
            'invoice_language' => $invoiceLanguage,
            'payment_terms_days' => $validated['paymentTermsDays'] === '' || $validated['paymentTermsDays'] === null ? null : (int) $validated['paymentTermsDays'],
            'country' => $validated['country'] ?: null,
            'street_address' => $validated['streetAddress'] ?: null,
            'street_number' => $validated['streetNumber'] ?: null,
            'postal_code' => $validated['postalCode'] ?: null,
            'city' => $validated['city'] ?: null,
            'address' => $validated['address'] ?: null,
            'shipping_country' => $validated['shippingCountry'] ?: null,
            'shipping_street_address' => $validated['shippingStreetAddress'] ?: null,
            'shipping_street_number' => $validated['shippingStreetNumber'] ?: null,
            'shipping_postal_code' => $validated['shippingPostalCode'] ?: null,
            'shipping_city' => $validated['shippingCity'] ?: null,
        ];

        $partner = DB::transaction(function () use ($attributes, $validated) {
            $partner = $this->partner
                ? tap($this->partner)->update($attributes)
                : Partner::create($attributes + ['company_id' => $this->company->id]);

            $banks = collect($validated['bankAccounts'])
                ->filter(fn ($row) => trim((string) ($row['bank_name'] ?? '')) !== '' || trim((string) ($row['account_number'] ?? '')) !== '')
                ->values()
                ->take(5);

            $partner->bankAccounts()->delete();
            foreach ($banks as $index => $row) {
                $partner->bankAccounts()->create([
                    'bank_name' => $row['bank_name'] ?: null,
                    'account_number' => $row['account_number'] ?: null,
                    'position' => $index,
                ]);
            }

            $contacts = collect($validated['contacts'])
                ->filter(fn ($row) => collect($row)->contains(fn ($value) => trim((string) $value) !== ''))
                ->values()
                ->take(10);

            $partner->contacts()->delete();
            foreach ($contacts as $index => $row) {
                $partner->contacts()->create([
                    'position' => $index,
                    'salutation' => $row['salutation'] ?: null,
                    'first_name' => $row['first_name'] ?: null,
                    'last_name' => $row['last_name'] ?: null,
                    'email' => $row['email'] ?: null,
                    'phone' => $row['phone'] ?: null,
                    'mobile' => $row['mobile'] ?: null,
                ]);
            }

            return $partner;
        });

        return $this->redirect(route('partners.show', [$this->company, $partner]), navigate: true);
    }

    public function render()
    {
        return view('livewire.partner-form', [
            'nameSuggestions' => $this->nameSuggestions(),
        ]);
    }
}
