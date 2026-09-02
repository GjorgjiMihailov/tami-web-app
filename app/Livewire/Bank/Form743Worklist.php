<?php

namespace App\Livewire\Bank;

use App\Models\Form743;
use App\Support\Form743Status;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Работниот список на канцеларијата: сите необработени 743 обрасци, од сите
 * клиенти, на едно место.
 *
 * е-ПДД нема API — пријавата се внесува рачно. Затоа овој екран не поднесува
 * ништо; тој само кажува што чека и по кој ред. Најстариот прв, зашто тој е
 * најблиску до рокот.
 */
#[Layout('layouts.app')]
class Form743Worklist extends Component
{
    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->hasAnyRole(['admin', 'accountant']),
            403
        );
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
