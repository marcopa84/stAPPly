<section class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <flux:heading size="lg">Documenti</flux:heading>
            <flux:subheading>
                Regolamenti, guide, circolari: l'agente li cerca con cerca_documenti e cita il passaggio da cui risponde.
            </flux:subheading>
        </div>

        @unless ($loadError)
            <flux:badge size="sm">{{ count($documents) }} di {{ $maxDocuments }}</flux:badge>
        @endunless
    </div>

    @include('livewire.agents.partials.upload-form', [
        'label' => 'Carica un documento',
        'hint' => "Formati {$allowedExtensions}, fino a {$maxUploadMb} MB. Un file con lo stesso nome sostituisce quello già caricato.",
        'working' => 'Il backend sta leggendo il documento e calcolando gli embedding: può volerci qualche secondo.',
    ])

    @if ($loadError)
        <flux:callout variant="danger" icon="exclamation-triangle" heading="Non riesco a leggere i documenti" :text="$loadError" />
    @elseif ($documents === [])
        <flux:text>Nessun documento caricato.</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Nome</flux:table.column>
                <flux:table.column align="end">Chunk</flux:table.column>
                <flux:table.column align="end">Dimensione</flux:table.column>
                <flux:table.column>Caricato</flux:table.column>
                @can('manageFiles', $agent)
                    <flux:table.column><span class="sr-only">Azioni</span></flux:table.column>
                @endcan
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($documents as $document)
                    <flux:table.row wire:key="document-{{ $document->documentId }}">
                        <flux:table.cell variant="strong">{{ $document->filename }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $document->chunks }}</flux:table.cell>
                        <flux:table.cell align="end">{{ Number::fileSize($document->sizeBytes, precision: 1) }}</flux:table.cell>
                        <flux:table.cell>
                            <span title="{{ $document->uploadedAt->translatedFormat('j F Y, H:i') }} UTC">
                                {{ $document->uploadedAt->diffForHumans() }}
                            </span>
                        </flux:table.cell>
                        @can('manageFiles', $agent)
                            <flux:table.cell align="end">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="confirmDelete({{ Js::from($document->documentId) }}, {{ Js::from($document->filename) }})"
                                    aria-label="Elimina {{ $document->filename }}"
                                />
                            </flux:table.cell>
                        @endcan
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @include('livewire.agents.partials.delete-modal', [
        'modal' => 'delete-document',
        'heading' => 'Eliminare il documento?',
        'body' => "«{$deletingName}» e i suoi chunk spariscono dalla collection: l'agente non potrà più citarlo.",
    ])
</section>
