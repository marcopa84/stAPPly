<section class="w-full max-w-2xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('agents.index')" wire:navigate>Agenti</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('agents.show', $agent)" wire:navigate>{{ $agent->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Modifica</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" level="1">Modifica agente</flux:heading>
    <flux:subheading size="lg" class="mb-6">
        Le istruzioni nuove valgono dal prossimo messaggio in chat.
    </flux:subheading>

    <form wire:submit="save" class="space-y-6">
        @include('livewire.agents.partials.form-fields')

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">Salva</flux:button>
            <flux:button variant="ghost" :href="route('agents.show', $agent)" wire:navigate>Torna all'agente</flux:button>
        </div>
    </form>

    <flux:separator class="my-10" />

    <section class="space-y-4">
        <div>
            <flux:heading size="lg">Elimina agente</flux:heading>

            @can('delete', $agent)
                <flux:subheading>
                    Cancella l'agente con i suoi documenti, le sue tabelle e le conversazioni. Non si può annullare.
                </flux:subheading>
            @else
                <flux:subheading>
                    Gli agenti demo non si eliminano: restano pronti per la prova di stAPPly.
                </flux:subheading>
            @endcan
        </div>

        @can('delete', $agent)
            <flux:modal.trigger name="confirm-agent-deletion">
                <flux:button variant="danger" icon="trash">Elimina agente</flux:button>
            </flux:modal.trigger>

            <flux:modal name="confirm-agent-deletion" @close="$set('deleteError', null)" focusable class="max-w-lg">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Eliminare «{{ $agent->name }}»?</flux:heading>
                        <flux:text class="mt-2">
                            Documenti, tabelle e conversazioni dell'agente vengono cancellati anche dal backend.
                        </flux:text>
                    </div>

                    @if ($deleteError)
                        <flux:callout variant="danger" icon="exclamation-triangle" heading="L'agente non è stato eliminato" :text="$deleteError" />
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled">Annulla</flux:button>
                        </flux:modal.close>

                        <flux:button variant="danger" wire:click="delete">Elimina</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endcan
    </section>
</section>
