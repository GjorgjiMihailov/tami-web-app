<?php

namespace App\Livewire\Costs;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Models\OtherCost;
use App\Services\DocumentStorage;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Трошоците што не доаѓаат како влезна фактура: фискални сметки и слично.
 *
 * Оваа фаза го прави само записот и екранот. ДДВ и книжењето во главната книга
 * доаѓаат откако структурата ќе се одреди — види ја спецификацијата.
 */
#[Layout('layouts.app')]
class OtherCostIndex extends Component
{
    use InteractsWithWorkingYear;
    use WithFileUploads;

    public Company $company;

    public string $costDate = '';

    public string $description = '';

    public string $amount = '';

    public $newFile = null;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
        $this->costDate = WorkingYear::defaultDate($this->workingYear);
    }

    public function save(): void
    {
        Gate::authorize('view', $this->company);

        $data = $this->validate([
            'costDate' => [
                'required',
                'date',
                // Запис надвор од работната година веднаш би исчезнал од
                // списокот и би изгледал како да не се зачувал.
                'after_or_equal:'.$this->workingYearStart(),
                'before_or_equal:'.$this->workingYearEnd(),
            ],
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'newFile' => 'nullable|file|max:25600',
        ], [
            'costDate.required' => 'Внесете го датумот на трошокот.',
            'costDate.after_or_equal' => 'Датумот мора да биде во работната година '.$this->workingYear.'.',
            'costDate.before_or_equal' => 'Датумот мора да биде во работната година '.$this->workingYear.'.',
            'description.required' => 'Напишете што е трошокот.',
            'amount.required' => 'Внесете го износот.',
            'amount.numeric' => 'Износот мора да биде број.',
            'amount.min' => 'Износот мора да биде поголем од нула.',
            'newFile.max' => 'Документот не смее да биде поголем од 25 MB.',
        ]);

        $cost = OtherCost::create([
            'company_id' => $this->company->id,
            'cost_date' => $data['costDate'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'created_by' => auth()->id(),
        ]);

        if ($this->newFile !== null) {
            try {
                DocumentStorage::store($cost, $this->newFile, 'Other');
            } catch (\Throwable $e) {
                $cost->delete();

                throw $e;
            }
        }

        $this->reset(['description', 'amount', 'newFile']);
        $this->costDate = WorkingYear::defaultDate($this->workingYear);
    }

    public function render()
    {
        $costs = OtherCost::where('company_id', $this->company->id)
            // whereYear, не споредба на низи: со `date` cast SQLite запишува
            // и време, па записот од 31 декември паѓа надвор од опсегот што
            // завршува со '-12-31'. Истата грешка веќе не чинеше еднаш.
            ->whereYear('cost_date', $this->workingYear)
            ->with(['documents', 'creator'])
            ->orderByDesc('cost_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.costs.other-cost-index', [
            'costs' => $costs,
            // Собрано со bcadd по запис, како секаде во оваа апликација —
            // SQL SUM заокружува поинаку од она што екранот го покажува.
            'total' => $costs->reduce(fn (string $carry, OtherCost $cost) => bcadd($carry, $cost->amount, 2), '0.00'),
        ]);
    }
}
