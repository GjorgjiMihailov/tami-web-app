<?php

namespace App\Livewire\Payroll;

use App\Models\Company;
use App\Models\PpfCalculation;
use App\Models\PpfPayment;
use App\Support\Ppf\PpfValidator;
use App\Support\Ppf\StrictDate;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Аконтативни пресметки (ППФ) за исплати на физички лица (договор на дело,
 * авторски, регрес…). Една пресметка = еден XML со свој број; во ист ден може
 * да има повеќе пресметки, затоа исплатите се доделуваат на пресметка, не се
 * групираат по датум. Читачки за сите што ја гледаат платата; пишува само
 * managePayroll, а XML-от го симнува само канцеларијата (посебна рута).
 */
#[Layout('layouts.app')]
class PpfPaymentIndex extends Component
{
    public Company $company;

    /** '' = цела година, инаку 1–12. */
    public string $month = '';

    /** Датум на новата пресметка (основно денес). */
    public string $newDate = '';

    /** Пресметка што се менува (број и датум). */
    public ?int $editingId = null;

    public string $editNumber = '';

    public string $editDate = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->newDate = now()->toDateString();
    }

    public function createCalculation(): mixed
    {
        Gate::authorize('managePayroll', $this->company);

        $date = StrictDate::parse($this->newDate);

        if ($date === null || $date->year < 2018) {
            $this->addError('newDate', 'Внесете валиден датум на пресметка (од 01.01.2018).');

            return null;
        }

        $calculation = PpfCalculation::create([
            'company_id' => $this->company->id,
            'year' => $date->year,
            'number' => PpfCalculation::nextNumber($this->company->id, $date->year),
            'period_date' => $date->toDateString(),
            'created_by' => auth()->id(),
        ]);

        return $this->redirect(route('ppf-payments.create', [$this->company, $calculation]));
    }

    public function startEditing(int $calculationId): void
    {
        Gate::authorize('managePayroll', $this->company);

        $calculation = $this->calculation($calculationId);
        $this->editingId = $calculation->id;
        $this->editNumber = (string) $calculation->number;
        $this->editDate = $calculation->period_date->toDateString();
        $this->resetErrorBag();
    }

    public function cancelEditing(): void
    {
        $this->editingId = null;
        $this->resetErrorBag();
    }

    public function updateCalculation(): void
    {
        Gate::authorize('managePayroll', $this->company);

        $calculation = $this->calculation((int) $this->editingId);
        $date = StrictDate::parse($this->editDate);

        if ($date === null || $date->year < 2018) {
            $this->addError('editDate', 'Внесете валиден датум на пресметка (од 01.01.2018).');

            return;
        }

        if (! ctype_digit($this->editNumber) || (int) $this->editNumber < 1) {
            $this->addError('editNumber', 'Бројот мора да биде цел број од 1 нагоре.');

            return;
        }

        $taken = PpfCalculation::where('company_id', $this->company->id)
            ->where('year', $date->year)
            ->where('number', (int) $this->editNumber)
            ->whereKeyNot($calculation->id)
            ->exists();

        if ($taken) {
            $this->addError('editNumber', 'Веќе постои пресметка со тој број во '.$date->year.'.');

            return;
        }

        // Променет број или датум значи различен фајл: печатот на извозот се чисти.
        $calculation->update([
            'year' => $date->year,
            'number' => (int) $this->editNumber,
            'period_date' => $date->toDateString(),
            'exported_at' => null,
            'exported_by' => null,
        ]);

        $this->editingId = null;
    }

    public function deleteCalculation(int $calculationId): void
    {
        Gate::authorize('managePayroll', $this->company);

        // Исплатите на пресметката се бришат со неа (надворешен клуч со каскада).
        $this->calculation($calculationId)->delete();

        if ($this->editingId === $calculationId) {
            $this->editingId = null;
        }
    }

    public function deletePayment(int $paymentId): void
    {
        Gate::authorize('managePayroll', $this->company);

        $payment = PpfPayment::where('company_id', $this->company->id)->findOrFail($paymentId);
        $payment->calculation?->clearExport();
        $payment->delete();
    }

    private function calculation(int $id): PpfCalculation
    {
        return PpfCalculation::where('company_id', $this->company->id)->findOrFail($id);
    }

    public function render()
    {
        $year = WorkingYear::for($this->company);
        $month = ctype_digit($this->month) && (int) $this->month >= 1 && (int) $this->month <= 12 ? (int) $this->month : null;

        $this->company->loadMissing('bankAccounts');

        $calculations = PpfCalculation::where('company_id', $this->company->id)
            ->where('year', $year)
            ->when($month, fn ($query) => $query->whereMonth('period_date', $month))
            ->with('payments.payee')
            ->orderByDesc('period_date')
            ->orderByDesc('number')
            ->get()
            ->map(fn (PpfCalculation $calculation) => [
                'model' => $calculation,
                'total' => $calculation->payments->sum('gross_income'),
                'check' => PpfValidator::check($this->company, $calculation),
            ]);

        return view('livewire.payroll.ppf-payment-index', [
            'calculations' => $calculations,
            'year' => $year,
            'canExport' => auth()->user()?->hasAnyRole(['admin', 'accountant']) ?? false,
        ]);
    }
}
