<section class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <flux:heading size="lg">Tabelle</flux:heading>
            <flux:subheading>
                Dati strutturati: ogni file diventa una tabella che l'agente interroga in SQL, in sola lettura, con query_db.
            </flux:subheading>
        </div>

        @unless ($loadError)
            <flux:badge size="sm">{{ count($tables) }} di {{ $maxTables }}</flux:badge>
        @endunless
    </div>

    @include('livewire.agents.partials.upload-form', [
        'label' => 'Carica una tabella',
        'hint' => "Formati {$allowedExtensions} (del file .xlsx si legge il primo foglio), fino a {$maxUploadMb} MB. La prima riga contiene i nomi delle colonne. Un file con lo stesso nome sostituisce la tabella.",
        'working' => 'Il backend sta leggendo il file e creando la tabella…',
    ])

    @if ($loadError)
        <flux:callout variant="danger" icon="exclamation-triangle" heading="Non riesco a leggere le tabelle" :text="$loadError" />
    @elseif ($tables === [])
        <flux:text>Nessuna tabella caricata.</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Tabella</flux:table.column>
                <flux:table.column align="end">Righe</flux:table.column>
                <flux:table.column>Colonne</flux:table.column>
                <flux:table.column>Caricata</flux:table.column>
                @can('manageFiles', $agent)
                    <flux:table.column><span class="sr-only">Azioni</span></flux:table.column>
                @endcan
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($tables as $table)
                    <flux:table.row wire:key="table-{{ $table->name }}">
                        <flux:table.cell>
                            <div class="font-mono font-medium text-zinc-800 dark:text-white">{{ $table->name }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $table->filename }}</div>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ Number::format($table->rows) }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">
                            <div class="flex max-w-xl flex-wrap gap-1">
                                @foreach ($table->columns as $column)
                                    <flux:badge size="sm" color="zinc" class="font-mono">
                                        {{ $column->name }}&nbsp;<span class="opacity-60">{{ $column->type }}</span>
                                    </flux:badge>
                                @endforeach
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <span title="{{ $table->uploadedAt->translatedFormat('j F Y, H:i') }} UTC">
                                {{ $table->uploadedAt->diffForHumans() }}
                            </span>
                        </flux:table.cell>
                        @can('manageFiles', $agent)
                            <flux:table.cell align="end">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="confirmDelete({{ Js::from($table->name) }}, {{ Js::from($table->name) }})"
                                    aria-label="Elimina la tabella {{ $table->name }}"
                                />
                            </flux:table.cell>
                        @endcan
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @include('livewire.agents.partials.delete-modal', [
        'modal' => 'delete-table',
        'heading' => 'Eliminare la tabella?',
        'body' => "La tabella {$deletingName} sparisce dal database dell'agente: query_db non potrà più interrogarla.",
    ])
</section>
