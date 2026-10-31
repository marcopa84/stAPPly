<?php

namespace App\Livewire\Agents;

use App\Support\DemoLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * I documenti dell'agente: il backend li divide in chunk e li indicizza per cerca_documenti.
 */
class Documents extends FilesSection
{
    protected function extensions(): array
    {
        return $this->configuredExtensions('stapply.document_extensions');
    }

    protected function store(TemporaryUploadedFile $file): string
    {
        $document = $this->backend->uploadDocument($this->agent->backend_id, $file);

        return $document->replaced
            ? "«{$document->filename}» ha sostituito il documento con lo stesso nome ({$document->chunks} chunk)."
            : "«{$document->filename}» caricato e indicizzato ({$document->chunks} chunk).";
    }

    protected function destroy(string $id): void
    {
        $this->backend->deleteDocument($this->agent->backend_id, $id);
    }

    protected function storedCount(): int
    {
        return count($this->backend->documents($this->agent->backend_id));
    }

    protected function maxFiles(): int
    {
        return DemoLimits::maxDocuments(Auth::user());
    }

    protected function limitReachedMessage(): string
    {
        return "Nella demo un agente può avere al massimo {$this->maxFiles()} documenti: eliminane uno per caricarne un altro.";
    }

    public function render(): View
    {
        [$documents, $loadError] = $this->load(fn () => $this->backend->documents($this->agent->backend_id));

        return view('livewire.agents.documents', [
            'documents' => $documents,
            'loadError' => $loadError,
            'maxDocuments' => $this->maxFiles(),
            ...$this->uploadLimits(),
        ]);
    }
}
