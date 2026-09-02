<?php

namespace App\Livewire;

use App\Models\Document;
use App\Services\DocumentStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class DocumentManager extends Component
{
    use WithFileUploads;

    public Model $documentable;

    public $newFile = null;

    public string $newCategory = 'Other';

    public string $newNote = '';

    public function mount(Model $documentable): void
    {
        Gate::authorize('view', $documentable);

        $this->documentable = $documentable;
    }

    public function upload(): void
    {
        Gate::authorize('update', $this->documentable);

        $this->validate([
            'newFile' => 'required|file|max:25600',
            'newCategory' => ['required', Rule::in(Document::CATEGORIES)],
            'newNote' => 'nullable|string|max:255',
        ]);

        DocumentStorage::store(
            $this->documentable,
            $this->newFile,
            $this->newCategory,
            $this->newNote,
        );

        $this->reset(['newFile', 'newNote']);
        $this->newCategory = 'Other';
    }

    public function delete(int $documentId): void
    {
        Gate::authorize('update', $this->documentable);

        $this->documentable->documents()->findOrFail($documentId)->delete();
    }

    public function render()
    {
        return view('livewire.document-manager', [
            'documents' => $this->documentable->documents()->with('uploader')->latest()->get(),
            'categories' => Document::CATEGORIES,
        ]);
    }
}
