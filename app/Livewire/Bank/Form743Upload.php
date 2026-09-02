<?php

namespace App\Livewire\Bank;

use App\Models\Company;
use App\Models\Form743;
use App\Services\DocumentStorage;
use App\Support\Form743Status;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Екранот на кој клиентот ги качува своите 743 обрасци.
 *
 * Едно поле и ништо друго. Сите податоци се веќе на образецот — банката ги
 * пополнила — па барањето клиентот да ги прекуцува само би внело грешки во
 * нешто што потоа оди во УЈП. Записот се создава празен и чека канцеларијата.
 */
#[Layout('layouts.app')]
class Form743Upload extends Component
{
    use WithFileUploads;

    public Company $company;

    public $newFile = null;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
    }

    public function upload(): void
    {
        Gate::authorize('view', $this->company);

        $this->validate([
            'newFile' => 'required|file|max:25600',
        ], [
            'newFile.required' => 'Изберете го образецот што сакате да го качите.',
            'newFile.max' => 'Образецот не смее да биде поголем од 25 MB.',
        ]);

        $form = Form743::create([
            'company_id' => $this->company->id,
            'status' => Form743Status::PENDING,
            'uploaded_by' => auth()->id(),
        ]);

        try {
            DocumentStorage::store($form, $this->newFile, 'Other');
        } catch (\Throwable $e) {
            // Образец без фајл е задача што никој не може да ја заврши.
            $form->delete();

            throw $e;
        }

        $this->reset('newFile');
    }

    public function render()
    {
        return view('livewire.bank.form743-upload', [
            'forms' => $this->company->form743s()
                ->with('documents')
                ->latest()
                ->get(),
        ]);
    }
}
