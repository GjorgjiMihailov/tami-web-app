<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Го качува фајлот и го врзува за записот на кој припаѓа.
 *
 * Извадено од `DocumentManager` кога 743 обрасците добија свој екран за
 * качување: местото каде фајлот се именува и се складира треба да остане едно.
 * Патеката го содржи id-то на самиот документ, а тоа не постои пред првото
 * зачувување — оттаму двочекорниот запис подолу.
 */
class DocumentStorage
{
    public static function store(
        Model $documentable,
        TemporaryUploadedFile $file,
        string $category,
        ?string $note = null,
    ): Document {
        $originalFilename = basename($file->getClientOriginalName());

        $document = new Document([
            'company_id' => $documentable->company_id,
            'category' => $category,
            'note' => $note ?: null,
            // Времена вредност: вистинската патека зависи од id-то на овој
            // документ, кое сè уште не постои. Колоната е NOT NULL без
            // стандардна вредност, па не смее да остане непополнета.
            'path' => '',
            'original_filename' => $originalFilename,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => auth()->id(),
        ]);
        $document->documentable()->associate($documentable);
        $document->save();

        try {
            $document->path = $file->storeAs(
                "documents/{$documentable->company_id}/{$document->documentable_type}/{$documentable->id}",
                "{$document->id}_{$originalFilename}",
                'google'
            );
            $document->save();
        } catch (\Throwable $e) {
            // Празниот ред не смее да го преживее неуспешното качување —
            // се брише целосно, не меко.
            $document->forceDelete();

            throw $e;
        }

        return $document;
    }
}
