<?php

namespace App\Livewire;

use App\Models\Document;
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

        $document = new Document([
            'company_id' => $this->documentable->company_id,
            'category' => $this->newCategory,
            'note' => $this->newNote ?: null,
            // Placeholder: the real storage path depends on this document's
            // own id (see below), so it isn't known yet. The `path` column
            // is NOT NULL with no default, so we can't leave it unset on
            // this first save.
            'path' => '',
            'original_filename' => $this->newFile->getClientOriginalName(),
            'mime_type' => $this->newFile->getMimeType(),
            'size' => $this->newFile->getSize(),
            'uploaded_by' => auth()->id(),
        ]);
        $document->documentable()->associate($this->documentable);
        $document->save();

        $document->path = $this->newFile->storeAs(
            "documents/{$this->documentable->company_id}/{$document->documentable_type}/{$this->documentable->id}",
            "{$document->id}_{$document->original_filename}",
            'google'
        );
        $document->save();

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
