{{-- Campo di upload delle sezioni Documenti e Tabelle. Variabili: $label, $hint, $working. --}}
@can('manageFiles', $agent)
    <form wire:submit="upload" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:field>
            <flux:label>{{ $label }}</flux:label>
            <flux:description>{{ $hint }}</flux:description>

            {{-- Il campo file di Flux è wire:ignore: per svuotarlo quando il file si scarta si cambia la chiave del contenitore. --}}
            <div wire:key="file-input-{{ $fileInputKey }}">
                <flux:input type="file" wire:model="file" :accept="$accept" />
            </div>

            <flux:error name="file" />
        </flux:field>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="file">
                Carica
            </flux:button>

            <flux:text wire:loading wire:target="file">Invio del file in corso…</flux:text>
            <flux:text wire:loading wire:target="upload">{{ $working }}</flux:text>
        </div>
    </form>
@else
    <flux:callout icon="information-circle" color="zinc">
        <flux:callout.heading>Agente demo</flux:callout.heading>
        <flux:callout.text>
            I suoi documenti e le sue tabelle li carica lo script del backend: qui puoi consultarli, non modificarli.
            Per provare con i tuoi file crea un agente nuovo.
        </flux:callout.text>
    </flux:callout>
@endcan
