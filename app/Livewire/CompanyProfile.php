<?php

namespace App\Livewire;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class CompanyProfile extends Component
{
    use WithFileUploads;

    public Company $company;

    public bool $editing = false;

    public string $editName = '';

    public string $editShortName = '';

    public string $editTaxId = '';

    public string $editEmbg = '';

    public string $editMpinObvrznikCode = '';

    public string $editRegistrationNumber = '';

    public string $editNkdCode = '';

    public string $editNkdName = '';

    public string $editEmail = '';

    public string $editPhone = '';

    public string $editWebsite = '';

    public string $editAddress = '';

    public string $editStreetAddress = '';

    public string $editStreetNumber = '';

    public string $editPostalCode = '';

    public string $editCity = '';

    public string $editDirectorName = '';

    public string $editDirectorPhone = '';

    public string $editDirectorEmail = '';

    public bool $editIsVatRegistered = true;

    public bool $editUsesMaterial = true;

    public bool $editUsesStock = true;

    public bool $editUsesPayroll = true;

    public bool $editUsesFinance = true;

    public array $bankAccounts = [];

    public string $editLogoPosition = 'left';

    public string $editInvoiceFooterNote = '';

    public $newLogo = null;

    public string $editEfakturaMode = 'firm';

    public string $editEfakturaEujpId = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function startEdit(): void
    {
        Gate::authorize('update', $this->company);

        $this->editName = $this->company->name;
        $this->editShortName = (string) $this->company->short_name;
        $this->editTaxId = (string) $this->company->tax_id;
        $this->editEmbg = (string) $this->company->embg;
        $this->editMpinObvrznikCode = $this->company->mpin_obvrznik_code?->value ?? '';
        $this->editRegistrationNumber = (string) $this->company->registration_number;
        $this->editNkdCode = (string) $this->company->nkd_code;
        $this->editNkdName = (string) $this->company->nkd_name;
        $this->editEmail = (string) $this->company->email;
        $this->editPhone = (string) $this->company->phone;
        $this->editWebsite = (string) $this->company->website;
        $this->editAddress = (string) $this->company->address;
        $this->editStreetAddress = (string) $this->company->street_address;
        $this->editStreetNumber = (string) $this->company->street_number;
        $this->editPostalCode = (string) $this->company->postal_code;
        $this->editCity = (string) $this->company->city;
        $this->editDirectorName = (string) $this->company->director_name;
        $this->editDirectorPhone = (string) $this->company->director_phone;
        $this->editDirectorEmail = (string) $this->company->director_email;
        $this->editIsVatRegistered = $this->company->is_vat_registered;
        $this->editUsesMaterial = $this->company->uses_material;
        $this->editUsesStock = $this->company->uses_stock;
        $this->editUsesPayroll = $this->company->uses_payroll;
        $this->editUsesFinance = $this->company->uses_finance;

        $existing = $this->company->bankAccounts()->get();
        $this->bankAccounts = $existing->isEmpty()
            ? [['bank_name' => '', 'account_number' => '']]
            : $existing->map(fn ($row) => [
                'bank_name' => (string) $row->bank_name,
                'account_number' => (string) $row->account_number,
            ])->all();

        $this->editLogoPosition = $this->company->logo_position ?: 'left';
        $this->editInvoiceFooterNote = (string) $this->company->invoice_footer_note;
        $this->newLogo = null;

        $this->editEfakturaMode = $this->company->efaktura_credential_mode;
        $this->editEfakturaEujpId = (string) $this->company->efaktura_eujp_id;

        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
    }

    /**
     * е-Фактура бара ЕДБ, што физичко лице нема — табелата „Полиња по тип" во
     * docs/superpowers/specs/2026-08-21-client-profile-types-design.md.
     *
     * Копчето е скриено во профилот на физичко лице, но Livewire метод се вика
     * преку жица без разлика што е исцртано, па криењето во Blade не е брана.
     * Чуварот стои сам за себе: не се потпира на тоа со кој режим профилот е
     * создаден, како што ни создавањето не се потпира на овој чувар.
     */
    public function requestFirmEfakturaAccess(): void
    {
        Gate::authorize('view', $this->company);

        abort_if($this->company->type->isIndividual(), 403, 'е-Фактура важи само за профил на правно лице.');

        if ($this->company->efaktura_credential_mode !== Company::EFAKTURA_MODE_FIRM) {
            return;
        }

        $this->company->update(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REQUESTED]);
    }

    /**
     * Потпишувачкиот уред служи за е-Фактура, па важи по истото правило како
     * requestFirmEfakturaAccess() погоре: физичко лице нема ЕДБ и нема што да
     * потпишува. Картичката е скриена во Blade, но методот е достапен преку
     * жица, па чуварот мора да е тука.
     */
    public function registerSigningDevice(string $serialNumber, string $subjectName, string $notBefore, string $notAfter): void
    {
        abort_unless(
            auth()->user()->hasAnyRole(['admin', 'accountant'])
                && auth()->user()->visibleCompanies()->whereKey($this->company->id)->exists(),
            403
        );

        abort_if($this->company->type->isIndividual(), 403, 'Потпишувачки уред се регистрира само на профил на правно лице.');

        if (blank($serialNumber)) {
            $this->addError('signingDevice', 'Не е добиен сериски број од токенот.');

            return;
        }

        try {
            $notBeforeParsed = \Illuminate\Support\Carbon::parse($notBefore);
            $notAfterParsed = \Illuminate\Support\Carbon::parse($notAfter);
        } catch (\Exception) {
            $this->addError('signingDevice', 'Датумите од сертификатот не можат да се прочитаат.');

            return;
        }

        $this->company->update([
            'efaktura_token_serial_number' => $serialNumber,
            'efaktura_token_subject_name' => \Illuminate\Support\Str::limit($subjectName, 250, ''),
            'efaktura_token_not_before' => $notBeforeParsed,
            'efaktura_token_not_after' => $notAfterParsed,
            'efaktura_token_registered_at' => now(),
        ]);
    }

    public function updated(string $name, $value): void
    {
        if (! str_ends_with($name, '.account_number')) {
            return;
        }

        $lastIndex = array_key_last($this->bankAccounts);
        $currentIndex = (int) explode('.', $name)[1];

        if ($currentIndex === $lastIndex && trim((string) $value) !== '' && count($this->bankAccounts) < 5) {
            $this->bankAccounts[] = ['bank_name' => '', 'account_number' => ''];
        }
    }

    public function save(): void
    {
        Gate::authorize('update', $this->company);

        $validated = $this->validate([
            'editName' => 'required|string|max:255',
            'editShortName' => 'nullable|string|max:255',
            'editTaxId' => 'nullable|string|max:255',
            'editEmbg' => $this->company->type->isIndividual() && $this->editEmbg !== ''
                ? ['nullable', 'max:13', new \App\Rules\ValidEmbg]
                : ['nullable', 'max:13'],
            'editMpinObvrznikCode' => ['nullable', Rule::enum(\App\Support\Payroll\MpinObvrznik::class)],
            'editRegistrationNumber' => 'nullable|string|max:255',
            'editNkdCode' => 'nullable|string|max:255',
            'editNkdName' => 'nullable|string|max:255',
            'editEmail' => 'nullable|email|max:255',
            'editPhone' => 'nullable|string|max:255',
            'editWebsite' => 'nullable|string|max:255',
            'editAddress' => 'nullable|string|max:255',
            'editStreetAddress' => 'nullable|string|max:255',
            'editStreetNumber' => 'nullable|string|max:50',
            'editPostalCode' => 'nullable|string|max:20',
            'editCity' => 'nullable|string|max:255',
            'editDirectorName' => 'nullable|string|max:255',
            'editDirectorPhone' => 'nullable|string|max:255',
            'editDirectorEmail' => 'nullable|email|max:255',
            'editIsVatRegistered' => 'boolean',
            'editUsesMaterial' => 'boolean',
            'editUsesStock' => 'boolean',
            'editUsesPayroll' => 'boolean',
            'editUsesFinance' => 'boolean',
            'bankAccounts' => 'array|max:5',
            'bankAccounts.*.bank_name' => 'nullable|string|max:255',
            'bankAccounts.*.account_number' => 'nullable|string|max:255',
            'editLogoPosition' => ['required', Rule::in(['left', 'center', 'right'])],
            'editInvoiceFooterNote' => 'nullable|string|max:2000',
            'newLogo' => 'nullable|image|max:25600',
            'editEfakturaMode' => ['required', Rule::in([
                Company::EFAKTURA_MODE_OWN, Company::EFAKTURA_MODE_FIRM,
            ])],
            'editEfakturaEujpId' => 'nullable|string|max:100',
        ]);

        $isLegal = $this->company->type->isLegal();

        if ($isLegal && $validated['editEfakturaMode'] === Company::EFAKTURA_MODE_OWN && blank($validated['editEfakturaEujpId'])) {
            $this->addError('editEfakturaEujpId', 'X-EUJP-ID е задолжителен за сопствен е-Фактура пристап.');

            return;
        }

        DB::transaction(function () use ($validated, $isLegal) {
            $companyData = [
                'name' => $validated['editName'],
                'short_name' => $validated['editShortName'] ?: null,
                'email' => $validated['editEmail'] ?: null,
                'phone' => $validated['editPhone'] ?: null,
                'website' => $validated['editWebsite'] ?: null,
                'address' => $validated['editAddress'] ?: null,
                'street_address' => $validated['editStreetAddress'] ?: null,
                'street_number' => $validated['editStreetNumber'] ?: null,
                'postal_code' => $validated['editPostalCode'] ?: null,
                'city' => $validated['editCity'] ?: null,
                'logo_position' => $validated['editLogoPosition'],
                'invoice_footer_note' => $validated['editInvoiceFooterNote'] ?: null,
            ];

            // ЕМБГ е поле на физичко лице, како што ЕДБ и НКД се полиња на
            // правно лице — затоа се запишува под истиот услов, а не безусловно.
            if ($this->company->type->isIndividual()) {
                $companyData['embg'] = $validated['editEmbg'] ?: null;
            }

            // ЕДБ е поле на правно лице. editTaxId е јавно Livewire својство —
            // може да се постави преку жица без разлика што прикажува Blade-от
            // — а полето е скриено за физичко лице по зачувувањето, па ЕДБ
            // запишан овде би бил недостапен ниту за преглед, ниту за бришење.
            // Затоа се запишува под истиот услов, а не безусловно.
            if ($isLegal) {
                $companyData['tax_id'] = $validated['editTaxId'] ?: null;
            }

            // Полињата подолу важат само за правно лице (ДДВ обврзник, МПИН
            // обврзник, матичен број, НКД, директор, е-Фактура). Формата секогаш
            // испраќа некоја вредност за нив — вклучително стандардни вредности
            // како "firm" за е-Фактура режим или true за ДДВ обврзник — иако тие
            // полиња се скриени во формата за физичко лице. Затоа не смее да се
            // запишуваат безусловно: физичко лице нема ЕДБ, па режим на
            // е-Фактура и слично се бесмислени за него, а секое зачувување на
            // профилот (дури и на несврзано поле) би ги презапишало на секогаш.
            if ($isLegal) {
                $companyData['is_vat_registered'] = $validated['editIsVatRegistered'];
                $companyData['uses_material'] = $validated['editUsesMaterial'];
                // Залиха без Материјално не постои — се запишува исклучена без
                // разлика што дошло од формата.
                $companyData['uses_stock'] = $validated['editUsesMaterial'] && $validated['editUsesStock'];
                $companyData['uses_payroll'] = $validated['editUsesPayroll'];
                $companyData['uses_finance'] = $validated['editUsesFinance'];
                $companyData['mpin_obvrznik_code'] = $validated['editMpinObvrznikCode'] ?: null;
                $companyData['registration_number'] = $validated['editRegistrationNumber'] ?: null;
                $companyData['nkd_code'] = $validated['editNkdCode'] ?: null;
                $companyData['nkd_name'] = $validated['editNkdName'] ?: null;
                $companyData['director_name'] = $validated['editDirectorName'] ?: null;
                $companyData['director_phone'] = $validated['editDirectorPhone'] ?: null;
                $companyData['director_email'] = $validated['editDirectorEmail'] ?: null;
                $companyData['efaktura_credential_mode'] = $validated['editEfakturaMode'];

                if ($validated['editEfakturaMode'] === Company::EFAKTURA_MODE_OWN) {
                    if (filled($validated['editEfakturaEujpId'])) {
                        $companyData['efaktura_eujp_id'] = $validated['editEfakturaEujpId'];
                    }
                } else {
                    $companyData['efaktura_eujp_id'] = null;
                }
            }

            $this->company->update($companyData);

            $keptRows = collect($validated['bankAccounts'])
                ->filter(fn ($row) => trim((string) ($row['bank_name'] ?? '')) !== '' || trim((string) ($row['account_number'] ?? '')) !== '')
                ->values()
                ->take(5);

            $this->company->bankAccounts()->delete();
            foreach ($keptRows as $index => $row) {
                $this->company->bankAccounts()->create([
                    'bank_name' => $row['bank_name'] ?: null,
                    'account_number' => $row['account_number'] ?: null,
                    'position' => $index,
                ]);
            }

            if ($this->newLogo) {
                $path = $this->newLogo->store('logos/'.$this->company->id, 'public');
                $this->company->update(['logo_path' => $path]);
                $this->newLogo = null;
            }
        });

        $this->editing = false;
    }

    public function render()
    {
        return view('livewire.company-profile');
    }
}
