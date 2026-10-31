<section class="w-full max-w-2xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('agents.index')" wire:navigate>Agenti</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Nuovo agente</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" level="1">Nuovo agente</flux:heading>
    <flux:subheading size="lg" class="mb-6">
        Dopo averlo creato potrai caricargli i documenti e le tabelle.
    </flux:subheading>

    @if ($this->limitReason)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6" :heading="$this->limitReason" />
    @endif

    <form wire:submit="save" class="space-y-6">
        @include('livewire.agents.partials.form-fields')

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit" :disabled="$this->limitReason !== null">Crea agente</flux:button>
            <flux:button variant="ghost" :href="route('agents.index')" wire:navigate>Annulla</flux:button>
        </div>
    </form>
</section>
