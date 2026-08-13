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
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EmployeeForm extends Component
{
    public Company $company;

    public ?Employee $employee = null;

    public string $embg = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $municipalityCode = '';

    public string $bankAccount = '';

    public string $insuranceTypeCode = '0050';

    public string $registrationNumber = '';

    public string $employedOn = '';

    public string $terminatedOn = '';

    public string $movementCode = '';

    public string $exemptionCode = '';

    public int $weeklyHours = 40;

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

        $year = WorkingYear::for($company);
        $this->employedOn = WorkingYear::defaultDate($year);
        $this->salaryEffectiveFrom = WorkingYear::defaultDate($year);

        if ($employee === null) {
            Gate::authorize('create', Employee::class);

            return;
        }

        Gate::authorize('update', $employee);

        $this->employee = $employee;
        $this->embg = $employee->embg;
        $this->firstName = $employee->first_name;
        $this->lastName = $employee->last_name;
        $this->municipalityCode = (string) $employee->municipality_code;
        $this->bankAccount = $employee->bank_account;
        $this->insuranceTypeCode = $employee->insurance_type_code;
        $this->registrationNumber = (string) $employee->registration_number;
        $this->employedOn = $employee->employed_on->toDateString();
        $this->terminatedOn = $employee->terminated_on?->toDateString() ?? '';
        $this->movementCode = (string) $employee->movement_code;
        $this->exemptionCode = (string) $employee->exemption_code;
        $this->weeklyHours = $employee->weekly_hours;
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

    private function recompute(string $value, string $from): void
    {
        if ($this->syncing) {
            return;
        }

        $amount = (float) str_replace([' ', '.'], '', $value);

        if ($amount <= 0) {
            return;
        }

        $parameter = PayrollParameter::forDate($this->salaryEffectiveFrom ?: now()->toDateString());

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
    }

    public function save(): void
    {
        $validated = $this->validate([
            'embg' => ['required', 'string', new ValidEmbg],
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'municipalityCode' => 'nullable|string|max:16',
            'bankAccount' => 'required|string|max:34',
            'insuranceTypeCode' => 'required|string|max:16',
            'registrationNumber' => 'nullable|string|max:32',
            'employedOn' => 'required|date',
            'terminatedOn' => 'nullable|date|after_or_equal:employedOn',
            'movementCode' => 'nullable|string|max:16',
            'exemptionCode' => 'nullable|string|max:16',
            'weeklyHours' => 'required|integer|min:1|max:40',
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

        $attributes = [
            'company_id' => $this->company->id,
            'embg' => $validated['embg'],
            'first_name' => $validated['firstName'],
            'last_name' => $validated['lastName'],
            'municipality_code' => $validated['municipalityCode'] ?: null,
            'bank_account' => $validated['bankAccount'],
            'insurance_type_code' => $validated['insuranceTypeCode'],
            'registration_number' => $validated['registrationNumber'] ?: null,
            'employed_on' => $validated['employedOn'],
            'terminated_on' => $validated['terminatedOn'] ?: null,
            'movement_code' => $validated['movementCode'] ?: null,
            'exemption_code' => $validated['exemptionCode'] ?: null,
            'weekly_hours' => $validated['weeklyHours'],
            'address' => $validated['address'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'email' => $validated['email'] ?: null,
        ];

        if ($this->employee === null) {
            $this->employee = Employee::create($attributes);
        } else {
            $this->employee->update($attributes);
        }

        // Only the side the user typed is stored. Persisting both would let
        // them drift apart the moment a rate changes.
        $typed = $this->basisTyped === 'gross' ? $this->gross : $this->net;
        $amount = (float) str_replace([' ', '.'], '', $typed);

        if ($amount > 0 && $this->salaryEffectiveFrom !== '') {
            EmployeeSalary::updateOrCreate(
                ['employee_id' => $this->employee->id, 'effective_from' => $this->salaryEffectiveFrom],
                ['amount' => $amount, 'basis' => $this->basisTyped],
            );
        }

        $this->redirectRoute('employees.index', $this->company, navigate: true);
    }

    public function render()
    {
        return view('livewire.employee-form', [
            'municipalities' => PayrollCode::ofType('opstina'),
            'insuranceTypes' => PayrollCode::ofType('vid_staz'),
            'movements' => PayrollCode::ofType('sifra_dviz'),
            'exemptions' => PayrollCode::ofType('osloboduvanje'),
            'history' => $this->employee?->salaries ?? collect(),
        ]);
    }
}
