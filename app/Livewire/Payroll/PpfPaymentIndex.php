<?php

namespace App\Livewire\Payroll;

use App\Models\Company;
use App\Models\PpfPayment;
use App\Support\Ppf\PpfValidator;
use App\Support\Ppf\StrictDate;
use App\Support\WorkingYear;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Исплати на физички лица (договор на дело, авторски, регрес…) за аконтативната
 * пресметка на УЈП. Групирани по датум на исплата: еден датум = еден ППФ XML.
 * Читачки за сите што ја гледаат платата; пишува само managePayroll,
 * а XML-от го симнува само канцеларијата (посебна рута).
 */
#[Layout('layouts.app')]
class PpfPaymentIndex extends Component
{
    public Company $company;

    /** '' = цела година, инаку 1–12. */
    public string $month = '';

    /** Датум на пресметката по датум на исплата (гггг-мм-дд => гггг-мм-дд); празно = денот на исплатата. */
    public array $periods = [];

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function deletePayment(int $paymentId): void
    {
        Gate::authorize('managePayroll', $this->company);

        PpfPayment::where('company_id', $this->company->id)->whereKey($paymentId)->delete();
    }

    /** Избраниот датум на пресметка, или null (= денот на исплата) ако е празен или невалиден. */
    private function periodFor(string $date): ?Carbon
    {
        $value = (string) ($this->periods[$date] ?? '');

        if ($value === '' || $value === $date) {
            return null;
        }

        return StrictDate::parse($value);
    }

    public function render()
    {
        $year = WorkingYear::for($this->company);
        $month = ctype_digit($this->month) && (int) $this->month >= 1 && (int) $this->month <= 12 ? (int) $this->month : null;

        $payments = PpfPayment::where('company_id', $this->company->id)
            ->whereYear('payment_date', $year)
            ->when($month, fn ($query) => $query->whereMonth('payment_date', $month))
            ->with('payee')
            ->orderByDesc('payment_date')
            ->orderBy('id')
            ->get();

        $this->company->loadMissing('bankAccounts');

        $groups = $payments->groupBy(fn (PpfPayment $p) => $p->payment_date->toDateString())
            ->map(function ($rows, $date) {
                // Првично секој датум на пресметка е денот на исплатата; изборот на клиентот не се пребришува.
                $this->periods[$date] ??= $date;
                $period = $this->periodFor($date);

                return [
                    'date' => $date,
                    'payments' => $rows,
                    'total' => $rows->sum('gross_income'),
                    'exported_at' => $rows->max('exported_at'),
                    'period' => $period?->toDateString() ?? $date,
                    'check' => PpfValidator::check($this->company, $rows, $period),
                ];
            });

        return view('livewire.payroll.ppf-payment-index', [
            'groups' => $groups,
            'year' => $year,
            'canExport' => auth()->user()?->hasAnyRole(['admin', 'accountant']) ?? false,
        ]);
    }
}
