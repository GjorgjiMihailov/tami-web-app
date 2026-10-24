<?php

namespace App\Livewire\Payroll;

use App\Models\Company;
use App\Models\PayrollCode;
use App\Models\PpfPayee;
use App\Models\PpfPayment;
use App\Rules\ValidEmbg;
use App\Support\Bank\AccountNumber;
use App\Support\Ppf\PpfCodes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Една исплата на физичко лице. Примателот се бира од досегашните или се
 * впишува нов — по ЕМБГ се наоѓа или создава (еден примател по ЕМБГ во фирма),
 * па втората исплата на истото лице не бара повторно внесување.
 */
#[Layout('layouts.app')]
class PpfPaymentForm extends Component
{
    public Company $company;

    public ?PpfPayment $payment = null;

    public string $payeeId = '';

    public string $name = '';

    public string $surname = '';

    public string $embg = '';

    public string $municipalityCode = '';

    public string $bankAccount = '';

    public string $paymentDate = '';

    public string $typeCode = 'T1';

    public string $subtypeCode = '1014';

    public string $gross = '';

    public string $paymentTypeCode = '00001';

    public string $advance = '';

    public string $vatAmount = '';

    public string $grossFree = '';

    public string $decisionNumber = '';

    public string $decisionDate = '';

    public string $comment = '';

    public function mount(Company $company, ?PpfPayment $payment = null): void
    {
        Gate::authorize('view', $company);
        Gate::authorize('managePayroll', $company);

        $this->company = $company;

        if ($payment?->exists) {
            abort_unless($payment->company_id === $company->id, 404);

            $this->payment = $payment->load('payee');
            $payee = $payment->payee;
            $this->payeeId = (string) $payee->id;
            $this->name = $payee->name;
            $this->surname = $payee->surname;
            $this->embg = $payee->embg;
            $this->municipalityCode = $payee->municipality_code;
            $this->bankAccount = (string) $payee->bank_account;
            $this->paymentDate = $payment->payment_date->toDateString();
            $this->typeCode = $payment->type_income_code;
            $this->subtypeCode = (string) $payment->subtype_income_code;
            $this->gross = (string) $payment->gross_income;
            $this->paymentTypeCode = $payment->payment_type_code;
            $this->advance = (string) ($payment->advance ?? '');
            $this->vatAmount = (string) ($payment->vat_amount ?? '');
            $this->grossFree = (string) ($payment->gross_free ?? '');
            $this->decisionNumber = (string) $payment->decision_number;
            $this->decisionDate = $payment->decision_date?->toDateString() ?? '';
            $this->comment = (string) $payment->comment;

            return;
        }

        $this->paymentDate = now()->toDateString();
    }

    /** Избран постоен примател: податоците се префрлаат (може да се поправат). */
    public function updatedPayeeId(): void
    {
        $payee = $this->payeeId === '' ? null : PpfPayee::where('company_id', $this->company->id)->find($this->payeeId);

        $this->name = $payee->name ?? '';
        $this->surname = $payee->surname ?? '';
        $this->embg = $payee->embg ?? '';
        $this->municipalityCode = $payee->municipality_code ?? '';
        $this->bankAccount = (string) ($payee->bank_account ?? '');

        if ($payee === null) {
            $this->payeeId = '';
        }
    }

    /** Датумот го менува шифрарникот што важи (стар 2018, нов од 2019). */
    public function updatedPaymentDate(): void
    {
        $date = $this->parsedDate();

        if ($date === null || ! PpfCodes::typeValidOn($this->typeCode, $date)) {
            $this->typeCode = '';
            $this->subtypeCode = '';
        }
    }

    public function updatedTypeCode(): void
    {
        $this->subtypeCode = '';
    }

    public function save(): mixed
    {
        Gate::authorize('managePayroll', $this->company);

        $date = $this->parsedDate();
        $municipalities = $this->municipalities()->pluck('code')->all();

        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'embg' => ['required', 'string', new ValidEmbg],
            'municipalityCode' => ['required', Rule::in($municipalities)],
            'bankAccount' => ['nullable', 'string', 'max:50', Rule::requiredIf($this->paymentTypeCode === '00001')],
            'paymentDate' => ['required', 'date', 'after_or_equal:2018-01-01'],
            'typeCode' => ['required', function (string $attribute, mixed $value, \Closure $fail) use ($date) {
                if ($date !== null && ! PpfCodes::typeValidOn((string) $value, $date)) {
                    $fail('Овој вид приход не важи за избраниот датум на исплата.');
                }
            }],
            // Видот без подвид во шифрарникот (T2, T8…) се зачувува без подвид, а XML се
            // блокира со објаснување. Празна вредност не ја повикува функцијата, па
            // „задолжително“ е посебно правило.
            'subtypeCode' => [
                Rule::requiredIf(fn () => PpfCodes::typeHasSubtypes($this->typeCode)),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! PpfCodes::subtypeBelongsTo($this->typeCode, (string) $value)) {
                        $fail('Подвидот не припаѓа на избраниот вид.');
                    }
                },
            ],
            'gross' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'paymentTypeCode' => ['required', Rule::in(array_column(PpfCodes::paymentTypes(), 'code'))],
            'advance' => 'nullable|integer|min:0',
            'vatAmount' => 'nullable|integer|min:0',
            'grossFree' => 'nullable|integer|min:0',
            'decisionNumber' => 'nullable|string|max:64',
            'decisionDate' => 'nullable|date',
            'comment' => 'nullable|string|max:255',
        ], attributes: [
            'name' => 'име', 'surname' => 'презиме', 'embg' => 'ЕМБГ', 'municipalityCode' => 'општина',
            'bankAccount' => 'трансакциска сметка', 'paymentDate' => 'датум на исплата', 'typeCode' => 'вид приход',
            'subtypeCode' => 'подвид', 'gross' => 'бруто износ', 'paymentTypeCode' => 'начин на плаќање',
            'advance' => 'аванс', 'vatAmount' => 'ДДВ', 'grossFree' => 'ослободен износ',
            'decisionNumber' => 'број на решение', 'decisionDate' => 'датум на решение', 'comment' => 'коментар',
        ], messages: [
            'gross.integer' => 'УЈП прима цели денари — впишете број без децимали.',
            'subtypeCode.required' => 'Изберете подвид на приход.',
            'bankAccount.required' => 'За плаќање на трансакциска сметка внесете ја сметката на примателот.',
        ]);

        DB::transaction(function () use ($validated) {
            $payee = PpfPayee::firstOrNew(['company_id' => $this->company->id, 'embg' => $validated['embg']]);
            $payee->fill([
                'name' => $validated['name'],
                'surname' => $validated['surname'],
                'municipality_code' => $validated['municipalityCode'],
                'bank_account' => AccountNumber::key($validated['bankAccount'] ?? '') ?: null,
            ])->save();

            $attributes = [
                'ppf_payee_id' => $payee->id,
                'type_income_code' => $validated['typeCode'],
                'subtype_income_code' => $validated['subtypeCode'] ?: null,
                'gross_income' => (int) $validated['gross'],
                'payment_date' => $validated['paymentDate'],
                'payment_type_code' => $validated['paymentTypeCode'],
                'advance' => $this->integerOrNull($validated['advance'] ?? null),
                'vat_amount' => $this->integerOrNull($validated['vatAmount'] ?? null),
                'gross_free' => $this->integerOrNull($validated['grossFree'] ?? null),
                'decision_number' => $validated['decisionNumber'] ?: null,
                'decision_date' => $validated['decisionDate'] ?: null,
                'comment' => $validated['comment'] ?: null,
            ];

            if ($this->payment === null) {
                PpfPayment::create($attributes + ['company_id' => $this->company->id, 'created_by' => auth()->id()]);
            } else {
                // Променета исплата повторно треба да се симне: печатот на извозот се чисти.
                $this->payment->update($attributes + ['exported_at' => null, 'exported_by' => null]);
            }
        });

        return $this->redirect(route('ppf-payments.index', $this->company));
    }

    private function integerOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function parsedDate(): ?Carbon
    {
        try {
            return $this->paymentDate === '' ? null : Carbon::parse($this->paymentDate);
        } catch (\Throwable) {
            return null;
        }
    }

    private function municipalities()
    {
        return PayrollCode::ofType('opstina')->where('code', '!=', '999')->values();
    }

    public function render()
    {
        $date = $this->parsedDate();

        return view('livewire.payroll.ppf-payment-form', [
            'payees' => PpfPayee::where('company_id', $this->company->id)->orderBy('name')->orderBy('surname')->get(),
            'municipalities' => $this->municipalities(),
            'types' => PpfCodes::typesFor($date),
            'subtypes' => $this->typeCode === '' ? [] : PpfCodes::subtypes($this->typeCode),
            'paymentTypes' => PpfCodes::paymentTypes(),
            'typeHasNoSubtypes' => $this->typeCode !== '' && PpfCodes::typeExists($this->typeCode) && ! PpfCodes::typeHasSubtypes($this->typeCode),
        ]);
    }
}
