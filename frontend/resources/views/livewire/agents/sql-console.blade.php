<section class="space-y-6">
    <div>
        <flux:heading size="lg">Console SQL</flux:heading>
        <flux:subheading>
            La stessa esecuzione in sola lettura di query_db: scrivi la SELECT a mano e confronta il risultato con la risposta dell'agente.
        </flux:subheading>
    </div>

    @if ($loadError)
        <flux:callout variant="danger" icon="exclamation-triangle" heading="Non riesco a leggere le tabelle" :text="$loadError" />
    @elseif ($tables === [])
        <flux:callout icon="information-circle" color="zinc">
            <flux:callout.heading>Nessuna tabella da interrogare</flux:callout.heading>
            <flux:callout.text>
                La console lavora sulle tabelle dell'agente: caricane una nella sezione Tabelle.
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="min-w-0 space-y-4">
                <form wire:submit="run" class="space-y-3">
                    <flux:textarea
                        wire:model="sql"
                        label="Query"
                        rows="5"
                        class="font-mono"
                        spellcheck="false"
                        placeholder="SELECT stato, COUNT(*) AS quante FROM {{ $tables[0]->name }} GROUP BY stato"
                        x-on:keydown.meta.enter.prevent="$el.form.requestSubmit()"
                        x-on:keydown.ctrl.enter.prevent="$el.form.requestSubmit()"
                    />

                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button type="submit" variant="primary" icon="play">Esegui</flux:button>
                        <flux:text size="sm">Solo SELECT o WITH, al massimo 200 righe. Ctrl+Invio (⌘+Invio su Mac) per eseguire.</flux:text>
                    </div>
                </form>

                @if ($error)
                    <flux:callout variant="danger" icon="exclamation-triangle" heading="La query non è andata" :text="$error" />
                @endif

                @if ($result)
                    @if ($result->truncated)
                        <flux:callout
                            variant="warning"
                            icon="scissors"
                            heading="Risultato tagliato"
                            text="Il backend ha restituito solo le prime righe: aggiungi un filtro o un LIMIT."
                        />
                    @endif

                    @if ($result->rows === [])
                        <flux:text>La query non ha restituito righe.</flux:text>
                    @else
                        <flux:text size="sm">
                            {{ count($result->rows) === 1 ? '1 riga' : count($result->rows).' righe' }}
                        </flux:text>

                        @php($numeric = array_map($result->isNumeric(...), array_keys($result->columns)))

                        <flux:table>
                            <flux:table.columns>
                                @foreach ($result->columns as $column)
                                    <flux:table.column :align="$numeric[$loop->index] ? 'end' : 'start'" class="font-mono">{{ $column }}</flux:table.column>
                                @endforeach
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($result->rows as $row)
                                    <flux:table.row wire:key="sql-row-{{ $loop->index }}">
                                        @foreach ($row as $value)
                                            <flux:table.cell :align="$numeric[$loop->index] ? 'end' : 'start'" class="font-mono">
                                                @if ($value === null)
                                                    <span class="text-zinc-400 italic">NULL</span>
                                                @else
                                                    {{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}
                                                @endif
                                            </flux:table.cell>
                                        @endforeach
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    @endif
                @endif
            </div>

            <aside class="space-y-3" aria-label="Schema delle tabelle">
                <flux:heading>Schema</flux:heading>

                @foreach ($tables as $table)
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="schema-{{ $table->name }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate font-mono font-medium text-zinc-800 dark:text-white">{{ $table->name }}</span>

                            <flux:button
                                size="xs"
                                variant="ghost"
                                x-on:click="$wire.sql = {{ Js::from("SELECT * FROM {$table->name} LIMIT 20") }}"
                            >
                                Prova
                            </flux:button>
                        </div>

                        <flux:text size="sm">{{ Number::format($table->rows) }} righe, da {{ $table->filename }}</flux:text>

                        <ul class="mt-2 space-y-0.5 font-mono text-xs">
                            @foreach ($table->columns as $column)
                                <li class="flex justify-between gap-2">
                                    <span class="truncate text-zinc-700 dark:text-zinc-200">{{ $column->name }}</span>
                                    <span class="text-zinc-400">{{ $column->type }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </aside>
        </div>
    @endif
</section>
