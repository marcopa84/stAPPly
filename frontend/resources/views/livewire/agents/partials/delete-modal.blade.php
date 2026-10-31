{{-- Conferma di eliminazione delle sezioni Documenti e Tabelle. Variabili: $modal (il nome), $heading, $body. --}}
<flux:modal :name="$modal" class="max-w-md md:min-w-md" @close="closeDeleteModal" wire:model="showDeleteModal">
    <div class="space-y-6">
        <div class="space-y-2">
            <flux:heading size="lg">{{ $heading }}</flux:heading>
            <flux:text>{{ $body }}</flux:text>
        </div>

        @if ($deleteError)
            <flux:callout variant="danger" icon="exclamation-triangle" heading="Eliminazione non riuscita" :text="$deleteError" />
        @endif

        <div class="flex justify-end gap-3">
            <flux:button variant="outline" wire:click="closeDeleteModal">Annulla</flux:button>
            <flux:button variant="danger" wire:click="delete">Elimina</flux:button>
        </div>
    </div>
</flux:modal>
