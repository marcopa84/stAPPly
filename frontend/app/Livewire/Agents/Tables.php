<?php

namespace App\Livewire\Agents;

use App\Services\Backend\BackendClient;
use App\Support\UsageLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Le tabelle dell'agente: ogni .csv o .xlsx diventa una tabella SQLite per query_db.
 */
class Tables extends FilesSection
{
    protected function extensions(): array
    {
        return $this->configuredExtensions('stapply.table_extensions');
    }

    protected function store(TemporaryUploadedFile $file): string
    {
        $table = $this->backend->uploadTable($this->agent->backend_id, $file);

        return $table->replaced
            ? "La tabella {$table->name} è stata sostituita con «{$table->filename}» ({$table->rows} righe)."
            : "«{$table->filename}» caricato nella tabella {$table->name} ({$table->rows} righe).";
    }

    protected function destroy(string $id): void
    {
        $this->backend->deleteTable($this->agent->backend_id, $id);
    }

    protected function idPattern(): string
    {
        return BackendClient::TABLE_PATTERN;
    }

    protected function storedCount(): int
    {
        return count($this->backend->tables($this->agent->backend_id));
    }

    protected function maxFiles(): int
    {
        return UsageLimits::maxTables(Auth::user());
    }

    protected function limitReachedMessage(): string
    {
        return "Nella demo un agente può avere al massimo {$this->maxFiles()} tabelle: eliminane una per caricarne un'altra.";
    }

    public function render(): View
    {
        [$tables, $loadError] = $this->load(fn () => $this->backend->tables($this->agent->backend_id));

        return view('livewire.agents.tables', [
            'tables' => $tables,
            'loadError' => $loadError,
            'maxTables' => $this->maxFiles(),
            ...$this->uploadLimits(),
        ]);
    }
}
