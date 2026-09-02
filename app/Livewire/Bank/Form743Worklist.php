<?php

namespace App\Livewire\Bank;

use App\Models\Form743;
use App\Support\Form743Status;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Работниот список на канцеларијата: сите необработени 743 обрасци, од сите
 * клиенти, на едно место.
 *
 * е-ПДД нема API — пријавата се внесува рачно на порталот на УЈП. Затоа овој
 * екран не поднесува ништо; тој кажува што чека и по кој ред (најстариот прв,
 * бидејќи е најблиску до рокот), а потоа го носи записот што е внесен.
 *
 * Обработката се пополнува тука, во редот, а не на посебен екран: образецот е
 * отворен во еден прозорец, а петте полиња се препишуваат од него.
 */
#[Layout('layouts.app')]
class Form743Worklist extends Component
{
    public ?int $editingId = null;

    public string $payer = '';

    public string $amount = '';

    public string $currency = '';

    public string $paymentDate = '';

    public string $basis = '';

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->hasAnyRole(['admin', 'accountant']),
            403
        );
    }

    public function edit(int $formId): void
    {
        $form = $this->locate($formId);
        Gate::authorize('update', $form);

        $this->editingId = $form->id;
        $this->reset(['payer', 'amount', 'currency', 'paymentDate', 'basis']);
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->reset(['payer', 'amount', 'currency', 'paymentDate', 'basis']);
        $this->resetValidation();
    }

    /**
     * Едно копче: полињата се запишуваат и задачата се затвора. Нема меѓусостојба
     * „внесено ама неподнесено" — сметководителот кликнува откако веќе ја внел
     * пријавата во е-ПДД, па записот е потврда дека тоа е сторено.
     */
    public function save(): void
    {
        $form = $this->locate($this->editingId);
        Gate::authorize('update', $form);

        $data = $this->validate([
            'payer' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3|alpha',
            'paymentDate' => 'required|date|before_or_equal:today',
            'basis' => 'required|string|max:255',
        ], [
            'payer.required' => 'Внесете кој ја извршил исплатата.',
            'amount.required' => 'Внесете го износот од образецот.',
            'amount.numeric' => 'Износот мора да биде број.',
            'amount.min' => 'Износот мора да биде поголем од нула.',
            'currency.required' => 'Внесете ја девизата.',
            'currency.size' => 'Девизата се пишува со три букви, на пример EUR.',
            'currency.alpha' => 'Девизата се пишува со три букви, на пример EUR.',
            'paymentDate.required' => 'Внесете го датумот на исплата.',
            'paymentDate.before_or_equal' => 'Датумот на исплата не може да биде во иднина.',
            'basis.required' => 'Внесете го основот на исплатата.',
        ]);

        $form->update([
            'payer' => $data['payer'],
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'payment_date' => $data['paymentDate'],
            'basis' => $data['basis'],
            'status' => Form743Status::FILED,
            'filed_by' => auth()->id(),
            'filed_at' => now(),
        ]);

        $this->cancel();
    }

    /**
     * Записот се бара низ истиот опсег што го гледа списокот. Без тоа, id од
     * туѓ клиент испратено рачно би стигнало до `update`.
     */
    private function locate(?int $formId): Form743
    {
        abort_if($formId === null, 404);

        return Form743::whereIn('company_id', auth()->user()->visibleCompanies()->select('id'))
            ->findOrFail($formId);
    }

    public function render()
    {
        return view('livewire.bank.form743-worklist', [
            'forms' => Form743::query()
                ->where('status', Form743Status::PENDING)
                ->whereIn('company_id', auth()->user()->visibleCompanies()->select('id'))
                ->with(['company', 'uploader', 'documents'])
                ->orderBy('created_at')
                ->get(),
        ]);
    }
}
