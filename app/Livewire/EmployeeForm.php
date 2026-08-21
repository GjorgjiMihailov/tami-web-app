<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollCode;
use App\Models\PayrollParameter;
use App\Rules\ValidEmbg;
use App\Support\Payroll\SalaryCalculator;
use App\Support\WorkingYear;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.app')]
class EmployeeForm extends Component
{
    public Company $company;

    public ?Employee $employee = null;

    /** Captured in mount() — render() must never read the session or request. */
    public int $workingYear = 0;

    public string $embg = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $municipalityCode = '';

    public string $healthAreaCode = '';

    public string $bankAccount = '';

    public string $insuranceTypeCode = '0050';

    public string $registrationNumber = '';

    public string $employedOn = '';

    public string $terminatedOn = '';

    public string $movementCode = '';

    public string $exemptionCode = '';

    public int $weeklyHours = 40;

    public int $priorServiceMonths = 0;

    public string $address = '';

    public string $phone = '';

    public string $email = '';

    public string $salaryEffectiveFrom = '';

    public string $gross = '';

    public string $net = '';

    /** Which of the two salary fields the user actually typed into. */
    public string $basisTyped = 'gross';

    /** Guards the two-way salary fields against recomputing each other forever. */
    private bool $syncing = false;

    public function mount(Company $company, ?Employee $employee = null): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;

        $this->workingYear = WorkingYear::for($company);
        $this->employedOn = WorkingYear::defaultDate($this->workingYear);
        $this->salaryEffectiveFrom = WorkingYear::defaultDate($this->workingYear);

        if ($employee === null) {
            Gate::authorize('create', Employee::class);

            return;
        }

        Gate::authorize('update', $employee);

        // The URL carries both ids independently. Without this, an admin (or an
        // accountant with two client companies) could open
        // /companies/{A}/employees/{employee-of-B}/edit and reparent the
        // employee — with their whole salary history — by saving. Same guard as
        // SalesInvoiceForm and PurchaseInvoiceForm.
        if ($employee->company_id !== $company->id) {
            abort(404);
        }

        $this->employee = $employee;
        $this->embg = $employee->embg;
        $this->firstName = $employee->first_name;
        $this->lastName = $employee->last_name;
        $this->municipalityCode = (string) $employee->municipality_code;
        $this->healthAreaCode = (string) $employee->health_area_code;
        $this->bankAccount = $employee->bank_account;
        $this->insuranceTypeCode = $employee->insurance_type_code;
        $this->registrationNumber = (string) $employee->registration_number;
        $this->employedOn = $employee->employed_on->toDateString();
        $this->terminatedOn = $employee->terminated_on?->toDateString() ?? '';
        $this->movementCode = (string) $employee->movement_code;
        $this->exemptionCode = (string) $employee->exemption_code;
        $this->weeklyHours = $employee->weekly_hours;
        $this->priorServiceMonths = $employee->prior_service_months;
        $this->address = (string) $employee->address;
        $this->phone = (string) $employee->phone;
        $this->email = (string) $employee->email;
    }

    public function updatedGross(string $value): void
    {
        $this->recompute($value, from: 'gross');
    }

    public function updatedNet(string $value): void
    {
        $this->recompute($value, from: 'net');
    }

    /**
     * The rates depend on the date, so moving "Важи од" after typing an amount
     * would otherwise leave the computed counterpart calculated against the
     * previous period. Only the typed side is stored, so the stored figure was
     * never wrong — but the one on screen was, which is worse than none.
     */
    public function updatedSalaryEffectiveFrom(): void
    {
        $typed = $this->basisTyped === 'gross' ? $this->gross : $this->net;

        $this->recompute($typed, from: $this->basisTyped);
    }

    /**
     * Parses a typed amount using the screen's own display convention — the
     * history table below writes it as number_format($amount, 0, ',', '.'),
     * dot for thousands, comma for decimals. A plain (float) cast stops at
     * the first comma, so "45000,50" would silently become 45000.0 without
     * this: spaces are dropped, dots (thousands) are dropped, and a comma
     * (decimals) becomes the decimal point.
     */
    private function parseAmount(string $value): float
    {
        $normalized = str_replace(' ', '', $value);
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        return (float) $normalized;
    }

    private function recompute(string $value, string $from): void
    {
        if ($this->syncing) {
            return;
        }

        $amount = $this->parseAmount($value);

        if ($amount <= 0) {
            return;
        }

        try {
            $parameter = PayrollParameter::forDate($this->salaryEffectiveFrom ?: now()->toDateString());
        } catch (RuntimeException $e) {
            $this->addError('salaryEffectiveFrom', 'Нема параметри за пресметка на плата за овој датум. Внесете ги параметрите во ПОСТАВКИ.');

            return;
        }

        $breakdown = $from === 'gross'
            ? SalaryCalculator::fromGross($amount, $parameter)
            : SalaryCalculator::fromNet($amount, $parameter);

        $this->syncing = true;

        if ($from === 'gross') {
            $this->net = (string) $breakdown->whole()['net'];
        } else {
            $this->gross = (string) $breakdown->whole()['gross'];
        }

        $this->syncing = false;

        $this->basisTyped = $from;

        $this->resetErrorBag('salaryEffectiveFrom');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'embg' => ['required', 'string', new ValidEmbg],
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            // МПИН needs SifraOpstina, so the card may not be saved without it.
            // The column stays nullable so a later partial import is possible.
            'municipalityCode' => 'required|string|max:16',
            'healthAreaCode' => ['nullable', 'string', 'max:16'],
            'bankAccount' => 'required|string|max:34',
            'insuranceTypeCode' => 'required|string|max:16',
            'registrationNumber' => 'nullable|string|max:32',
            'employedOn' => 'required|date',
            'terminatedOn' => 'nullable|date|after_or_equal:employedOn',
            'movementCode' => 'nullable|string|max:16',
            'exemptionCode' => 'nullable|string|max:16',
            'weeklyHours' => 'required|integer|min:1|max:40',
            'priorServiceMonths' => 'required|integer|min:0|max:720',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'salaryEffectiveFrom' => 'nullable|date',
        ]);

        $duplicate = Employee::where('company_id', $this->company->id)
            ->where('embg', $validated['embg'])
            ->when($this->employee !== null, fn ($q) => $q->whereKeyNot($this->employee->id))
            ->exists();

        if ($duplicate) {
            $this->addError('embg', 'Веќе постои вработен со овој ЕМБГ во оваа фирма.');

            return;
        }

        // company_id is deliberately absent: it belongs only to the create path
        // below, so no update can ever move an employee to another company.
        $attributes = [
            'embg' => $validated['embg'],
            'first_name' => $validated['firstName'],
            'last_name' => $validated['lastName'],
            'municipality_code' => $validated['municipalityCode'],
            'health_area_code' => $validated['healthAreaCode'] ?: null,
            'bank_account' => $validated['bankAccount'],
            'insurance_type_code' => $validated['insuranceTypeCode'],
            'registration_number' => $validated['registrationNumber'] ?: null,
            'employed_on' => $validated['employedOn'],
            'terminated_on' => $validated['terminatedOn'] ?: null,
            'movement_code' => $validated['movementCode'] ?: null,
            'exemption_code' => $validated['exemptionCode'] ?: null,
            'weekly_hours' => $validated['weeklyHours'],
            'prior_service_months' => $validated['priorServiceMonths'],
            'address' => $validated['address'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'email' => $validated['email'] ?: null,
        ];

        if ($this->employee === null) {
            $this->employee = Employee::create(['company_id' => $this->company->id] + $attributes);
        } else {
            $this->employee->update($attributes);
        }

        // Only the side the user typed is stored. Persisting both would let
        // them drift apart the moment a rate changes.
        $typed = $this->basisTyped === 'gross' ? $this->gross : $this->net;
        $amount = $this->parseAmount($typed);

        if ($amount > 0 && $this->salaryEffectiveFrom !== '') {
            EmployeeSalary::updateOrCreate(
                [
                    'employee_id' => $this->employee->id,
                    // The `date` cast writes through getDateFormat(), so SQLite
                    // stores '2026-07-01 00:00:00'. where() applies no casts, so
                    // a plain '2026-07-01' would never match its own row and each
                    // save would append a duplicate. Normalising the lookup key
                    // to the same instant is what makes this an update.
                    'effective_from' => Carbon::parse($this->salaryEffectiveFrom)->startOfDay(),
                ],
                ['amount' => $amount, 'basis' => $this->basisTyped],
            );
        }

        $this->redirectRoute('employees.index', $this->company, navigate: true);
    }

    public function render()
    {
        // The spec: the card *shows* the salary in force on the working year's
        // default date. It is shown, not offered for editing — prefilling the
        // two inputs would make every save of an unrelated field write a new
        // salary row dated today.
        $asOf = WorkingYear::defaultDate($this->workingYear);

        return view('livewire.employee-form', [
            'municipalities' => PayrollCode::ofType('opstina'),
            'insuranceTypes' => PayrollCode::ofType('vid_staz'),
            'movements' => PayrollCode::ofType('sifra_dviz'),
            'exemptions' => PayrollCode::ofType('osloboduvanje'),
            'history' => $this->employee?->salaries ?? collect(),
            'currentSalary' => $this->employee?->salaryOn($asOf),
            'asOf' => Carbon::parse($asOf),
        ]);
    }
}
