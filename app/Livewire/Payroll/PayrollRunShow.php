<?php

namespace App\Livewire\Payroll;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollRunLine;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\LineType;
use App\Support\Payroll\Mpin\MpinValidator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PayrollRunShow extends Component
{
    public Company $company;

    public PayrollRun $run;

    public ?int $selectedEmployeeId = null;

    public string $lineKind = PayrollRunLine::KIND_HOURS;

    public ?string $lineCode = '001';

    public string $lineDescription = '';

    public ?string $lineHours = null;

    public ?string $linePercent = null;

    public ?string $lineAmount = null;

    public function mount(Company $company, PayrollRun $run): void
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);

        $this->company = $company;
        $this->run = $run;
    }

    public function selectEmployee(int $id): void
    {
        $this->selectedEmployeeId = $id;
        $this->resetLineForm();
    }

    /** Keeps the percent in step with the chosen code without overriding a typed one. */
    public function updatedLineCode(?string $value): void
    {
        if ($value !== null && isset(LineType::OFFERED[$value])) {
            $this->linePercent = (string) LineType::defaultPercent($value);
            $this->lineKind = LineType::OFFERED[$value]['kind'];
            $this->lineDescription = LineType::label($value);
        }
    }

    public function saveLine(PayrollRunService $service): void
    {
        // mount() authorizes once when the component is instantiated; a
        // Livewire action call does not re-run it. EnsureAccountingAccess is
        // registered as persistent middleware and covers the update endpoint
        // too, but this second lock is cheap next to what a wrongly-booked
        // payroll run would cost, and it matches the pattern this branch
        // already established in PayrollParameterIndex::saveMonthHours().
        Gate::authorize('view', $this->company);

        if (! $this->guardDraft()) {
            return;
        }

        $rules = [
            'lineKind' => ['required', 'in:hours,amount,deduction'],
            'lineDescription' => ['required', 'string', 'max:255'],
        ];

        if ($this->lineKind === PayrollRunLine::KIND_HOURS) {
            // integer, not numeric: BrojCasovi is xs:int in mpin.xsd and a
            // fractional hour would only fail in 5c, at УЈП.
            $rules['lineCode'] = ['required', 'string', Rule::in(array_keys(LineType::OFFERED))];
            $rules['lineHours'] = ['required', 'integer', 'min:0', 'max:744'];
            $rules['linePercent'] = ['required', 'numeric', 'min:0', 'max:500'];
        } else {
            $rules['lineAmount'] = ['required', 'numeric', 'min:0'];
        }

        $this->validate($rules, attributes: [
            'lineKind' => 'вид', 'lineCode' => 'шифра', 'lineDescription' => 'опис',
            'lineHours' => 'часови', 'linePercent' => 'процент', 'lineAmount' => 'износ',
        ]);

        $runEmployee = $this->selectedEmployee();

        // A deduction is withheld from what the company pays. When the whole
        // month is borne by the Fund the company pays nothing, so there is
        // nothing to withhold from — and a deduction entered anyway would show
        // on the payslip while the ledger booked nothing for it. Refusing it is
        // the honest answer; the poster has a second guard behind this one.
        if ($this->lineKind === PayrollRunLine::KIND_DEDUCTION) {
            // This line-sum is NOT the same code path as PayrollRunService's
            // stored employer_gross column. It runs here, before saveLine()'s
            // recalculate() call, against the lines already on screen —
            // employer_gross would still hold last calculation's figure at
            // this point, not one that accounts for the line about to be
            // added. The two are expected to agree once recalculate() runs,
            // but this one has to be computed fresh because no recalculation
            // has happened yet.
            $employerGross = $runEmployee->lines
                ->where('kind', '!=', PayrollRunLine::KIND_DEDUCTION)
                ->where('borne_by', PayrollRunLine::BORNE_EMPLOYER)
                ->sum('amount');

            if (round((float) $employerGross, 2) <= 0) {
                $this->addError('lineAmount', 'Нема од што да се задржи — целата плата на овој вработен е на товар на друг.');

                return;
            }

            $remaining = round($runEmployee->net - $runEmployee->deductions_total, 2);

            if ((float) $this->lineAmount > $remaining) {
                $this->addError('lineAmount', 'Задршката е поголема од останатото нето за исплата.');

                return;
            }
        }

        PayrollRunLine::create([
            'payroll_run_employee_id' => $runEmployee->id,
            'kind' => $this->lineKind,
            'code' => $this->lineKind === PayrollRunLine::KIND_DEDUCTION ? null : $this->lineCode,
            'description' => $this->lineDescription,
            'hours' => $this->lineKind === PayrollRunLine::KIND_HOURS ? (int) $this->lineHours : null,
            'percent' => $this->lineKind === PayrollRunLine::KIND_HOURS ? (float) $this->linePercent : null,
            'amount' => $this->lineKind === PayrollRunLine::KIND_HOURS ? 0 : (float) $this->lineAmount,
            'borne_by' => $this->lineKind === PayrollRunLine::KIND_DEDUCTION
                ? PayrollRunLine::BORNE_EMPLOYER
                : LineType::borneBy($this->lineCode),
            'is_automatic' => false,
        ]);

        $this->run = $service->recalculate($this->run->fresh());
        $this->resetLineForm();
    }

    public function deleteLine(int $id, PayrollRunService $service): void
    {
        // See the comment on saveLine() above: mount() authorizes once, an
        // action call does not re-run it.
        Gate::authorize('view', $this->company);

        if (! $this->guardDraft()) {
            return;
        }

        // Scoped to this run, not a bare findOrFail. Without the scope any
        // user who can open one company's run could pass a line id belonging
        // to another company's run — including a confirmed one — and delete
        // it: guardDraft() above checks the status of the run on screen, not
        // of the run the line actually belongs to. The victim run would also
        // be left with stored totals that no longer match its lines, and
        // recalculate() refuses to run against a confirmed run, so it could
        // never heal itself.
        $line = PayrollRunLine::whereHas(
            'runEmployee',
            fn ($query) => $query->where('payroll_run_id', $this->run->id)
        )->findOrFail($id);

        if ($line->is_automatic) {
            $this->addError('lineKind', 'Минатиот труд се пресметува автоматски и не се брише.');

            return;
        }

        $line->delete();

        $this->run = $service->recalculate($this->run->fresh());
    }

    public function confirm(PayrollRunService $service): void
    {
        // See the comment on saveLine() above: mount() authorizes once, an
        // action call does not re-run it. This one posts to the general
        // ledger, so it is the highest-stakes of the four.
        Gate::authorize('view', $this->company);

        if (! $this->guardDraft()) {
            return;
        }

        $this->run = $service->confirm($this->run, (int) auth()->id());
    }

    public function returnToDraft(PayrollRunService $service): void
    {
        // See the comment on saveLine() above: mount() authorizes once, an
        // action call does not re-run it.
        Gate::authorize('view', $this->company);

        if ($this->run->isDraft()) {
            return;
        }

        $this->run = $service->returnToDraft($this->run, (int) auth()->id());
    }

    private function guardDraft(): bool
    {
        if ($this->run->isDraft()) {
            return true;
        }

        $this->addError('lineKind', 'Потврдена пресметка не се менува. Прво врати ја во нацрт.');

        return false;
    }

    private function selectedEmployee(): PayrollRunEmployee
    {
        return PayrollRunEmployee::where('payroll_run_id', $this->run->id)
            ->whereKey($this->selectedEmployeeId)
            ->firstOrFail();
    }

    private function resetLineForm(): void
    {
        $this->lineKind = PayrollRunLine::KIND_HOURS;
        $this->lineCode = '001';
        $this->lineDescription = LineType::label('001');
        $this->lineHours = null;
        $this->linePercent = '100';
        $this->lineAmount = null;
    }

    public function render()
    {
        $rows = $this->run->employees()->with(['employee', 'lines'])->get()
            ->sortBy(fn (PayrollRunEmployee $e) => $e->employee->last_name)
            ->values();

        $selected = $this->selectedEmployeeId === null
            ? null
            : $rows->firstWhere('id', $this->selectedEmployeeId);

        // Draft runs never reach the validator: MpinValidator itself would
        // report "must be confirmed first", but the screen already shows a
        // Confirm button for that — reporting it twice would just be noise.
        $mpin = $this->run->isDraft() ? null : MpinValidator::check($this->run);

        return view('livewire.payroll.payroll-run-show', [
            'rows' => $rows,
            'selected' => $selected,
            'offered' => LineType::OFFERED,
            'mpin' => $mpin,
        ]);
    }
}
