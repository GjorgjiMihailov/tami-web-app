<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\PayrollCode;
use App\Rules\ValidEmbg;
use App\Support\CompanyTabs;
use App\Support\Payroll\MpinObvrznik;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

    public string $editDirectorEmbg = '';

    public string $editDirectorPhone = '';

    public string $editDirectorEmail = '';

    public bool $editIsVatRegistered = true;

    public bool $editUsesForeignCurrency = false;

    public string $editPayrollObligationCode = '';

    public string $editPayrollAuthorizedPerson = '';

    public string $editPayrollPhonePrefix = '';

    public string $editPayrollPhone = '';

    public string $editPayrollMobile = '';

    public string $editPayrollMunicipalityCode = '';

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

        // Кој може да ја менува фирмата, форма гледа веднаш — без копче „Уреди".
        if (auth()->user()->can('update', $company)) {
            $this->startEdit();
        }
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
        $this->editDirectorEmbg = (string) $this->company->director_embg;
        $this->editDirectorPhone = (string) $this->company->director_phone;
        $this->editDirectorEmail = (string) $this->company->director_email;
        $this->editIsVatRegistered = (bool) $this->company->is_vat_registered;
        $this->editUsesForeignCurrency = (bool) $this->company->uses_foreign_currency;
        $this->editPayrollObligationCode = (string) $this->company->payroll_obligation_code;
        $this->editPayrollAuthorizedPerson = (string) $this->company->payroll_authorized_person;
        $this->editPayrollPhonePrefix = (string) $this->company->payroll_phone_prefix;
        $this->editPayrollPhone = (string) $this->company->payroll_phone;
        $this->editPayrollMobile = (string) $this->company->payroll_mobile;
        $this->editPayrollMunicipalityCode = (string) $this->company->payroll_municipality_code;

        $existing = $this->company->bankAccounts()->get();
        $this->bankAccounts = $existing->isEmpty()
            ? [['bank_name' => '', 'account_number' => '', 'iban' => '', 'swift' => '']]
            : $existing->map(fn ($row) => [
                'bank_name' => (string) $row->bank_name,
                'account_number' => (string) $row->account_number,
                'iban' => (string) $row->iban,
                'swift' => (string) $row->swift,
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
        $this->startEdit();
    }

    /**
     * Потпишувачкиот уред служи за е-Фактура, па важи по правилото:
     * физичко лице нема ЕДБ и нема што да
     * потпишува. Картичката е скриена во Blade, но методот е достапен преку
     * жица, па чуварот мора да е тука.
     */
    public function registerSigningDevice(string $serialNumber, string $subjectName, string $notBefore, string $notAfter): void
    {
        // Livewire не го повторува mount() при дејство, па правото се проверува тука.
        Gate::authorize('manageEfakturaDevice', $this->company);

        abort_if($this->company->type->isIndividual(), 403, 'Потпишувачки уред се регистрира само на профил на правно лице.');

        if (blank($serialNumber)) {
            $this->addError('signingDevice', 'Не е добиен сериски број од токенот.');

            return;
        }

        // Сериски број подолг од 100 знаци или со контролен знак (CR/LF...) не е валиден:
        // првиот не влегува во колоната, вториот го одбива HTTP-клиентот во заглавието X-SERIAL-NUMBER.
        if (mb_strlen($serialNumber) > 100 || preg_match('/[\x00-\x1F\x7F]/', $serialNumber)) {
            $this->addError('signingDevice', 'Сериски број од токенот не е валиден.');

            return;
        }

        try {
            $notBeforeParsed = Carbon::parse($notBefore);
            $notAfterParsed = Carbon::parse($notAfter);
        } catch (\Exception) {
            $this->addError('signingDevice', 'Датумите од сертификатот не можат да се прочитаат.');

            return;
        }

        $this->company->update([
            'efaktura_token_serial_number' => $serialNumber,
            'efaktura_token_subject_name' => Str::limit($subjectName, 250, ''),
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
            $this->bankAccounts[] = ['bank_name' => '', 'account_number' => '', 'iban' => '', 'swift' => ''];
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
                ? ['nullable', 'max:13', new ValidEmbg]
                : ['nullable', 'max:13'],
            'editMpinObvrznikCode' => ['nullable', Rule::enum(MpinObvrznik::class)],
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
            'editDirectorEmbg' => ['nullable', 'max:13', new ValidEmbg],
            'editDirectorPhone' => 'nullable|string|max:255',
            'editDirectorEmail' => 'nullable|email|max:255',
            'editIsVatRegistered' => 'boolean',
            'editUsesForeignCurrency' => 'boolean',
            'editPayrollObligationCode' => ['nullable', 'string', 'max:16', $this->codeRule('vid_obvrska')],
            'editPayrollAuthorizedPerson' => 'nullable|string|max:255',
            'editPayrollPhonePrefix' => 'nullable|string|max:8',
            'editPayrollPhone' => 'nullable|string|max:32',
            'editPayrollMobile' => 'nullable|string|max:32',
            'editPayrollMunicipalityCode' => ['nullable', 'string', 'max:16', $this->codeRule('opstina')],
            'bankAccounts' => 'array|max:5',
            'bankAccounts.*.bank_name' => 'nullable|string|max:255',
            'bankAccounts.*.account_number' => 'nullable|string|max:255',
            'bankAccounts.*.iban' => 'nullable|string|max:64',
            'bankAccounts.*.swift' => 'nullable|string|max:20',
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
                'uses_foreign_currency' => $validated['editUsesForeignCurrency'],
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
                $companyData['mpin_obvrznik_code'] = $validated['editMpinObvrznikCode'] ?: null;
                $companyData['registration_number'] = $validated['editRegistrationNumber'] ?: null;
                $companyData['nkd_code'] = $validated['editNkdCode'] ?: null;
                $companyData['nkd_name'] = $validated['editNkdName'] ?: null;
                $companyData['director_name'] = $validated['editDirectorName'] ?: null;
                $companyData['director_embg'] = $validated['editDirectorEmbg'] ?: null;
                $companyData['director_phone'] = $validated['editDirectorPhone'] ?: null;
                $companyData['director_email'] = $validated['editDirectorEmail'] ?: null;
                $companyData['payroll_obligation_code'] = $validated['editPayrollObligationCode'] ?: null;
                $companyData['payroll_authorized_person'] = $validated['editPayrollAuthorizedPerson'] ?: null;
                $companyData['payroll_phone_prefix'] = $validated['editPayrollPhonePrefix'] ?: null;
                $companyData['payroll_phone'] = $validated['editPayrollPhone'] ?: null;
                $companyData['payroll_mobile'] = $validated['editPayrollMobile'] ?: null;
                $companyData['payroll_municipality_code'] = $validated['editPayrollMunicipalityCode'] ?: null;
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
                ->filter(fn ($row) => trim((string) ($row['bank_name'] ?? '')) !== ''
                    || trim((string) ($row['account_number'] ?? '')) !== ''
                    || trim((string) ($row['iban'] ?? '')) !== ''
                    || trim((string) ($row['swift'] ?? '')) !== '')
                ->values()
                ->take(5);

            $this->company->bankAccounts()->delete();
            foreach ($keptRows as $index => $row) {
                $this->company->bankAccounts()->create([
                    'bank_name' => $row['bank_name'] ?: null,
                    'account_number' => $row['account_number'] ?: null,
                    // IBAN/SWIFT важат само за девизно работење.
                    'iban' => $validated['editUsesForeignCurrency'] ? ($row['iban'] ?? null ?: null) : null,
                    'swift' => $validated['editUsesForeignCurrency'] ? ($row['swift'] ?? null ?: null) : null,
                    'position' => $index,
                ]);
            }

            if ($this->newLogo) {
                $path = $this->newLogo->store('logos/'.$this->company->id, 'public');
                $this->company->update(['logo_path' => $path]);
                $this->newLogo = null;
            }
        });

        // Формата останува отворена; се вчитува повторно од зачуваното.
        $this->company->refresh();
        $this->startEdit();
        session()->flash('status', 'Промените се зачувани.');
    }

    /**
     * Шифра од шифрарник: се проверува само кога шифрарникот е вчитан. Додека
     * нема ниту еден запис од тој вид, полето не може да се одбие.
     */
    private function codeRule(string $type): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($type) {
            $known = PayrollCode::where('type', $type);

            if ($value !== null && $value !== '' && $known->exists() && ! $known->where('code', $value)->exists()) {
                $fail('Непозната шифра.');
            }
        };
    }

    public function render()
    {
        return view('livewire.company-profile', [
            'municipalities' => PayrollCode::ofType('opstina'),
            'obligations' => PayrollCode::ofType('vid_obvrska'),
            'tabs' => CompanyTabs::for(auth()->user(), $this->company, 'companies.profile'),
        ]);
    }
}
