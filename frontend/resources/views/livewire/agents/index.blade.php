<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Agenti</flux:heading>
            <flux:subheading size="lg">
                Ogni agente ha istruzioni, documenti e tabelle propri e risponde solo da quelli.
            </flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('agents.create')" wire:navigate>
            Nuovo agente
        </flux:button>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mb-6" :heading="session('status')" />
    @endif

    @if ($this->agents->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            <flux:icon.cpu-chip class="mx-auto size-10 text-zinc-400" />
            <flux:heading size="lg" class="mt-4">Non hai ancora agenti</flux:heading>
            <flux:text class="mx-auto mt-2 max-w-md">
                Crea un agente, scrivi di cosa si occupa e caricagli i documenti e le tabelle da cui rispondere.
            </flux:text>
            <flux:button variant="primary" icon="plus" class="mt-6" :href="route('agents.create')" wire:navigate>
                Crea il primo agente
            </flux:button>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->agents as $agent)
                <a
                    href="{{ route('agents.show', $agent) }}"
                    wire:navigate
                    wire:key="agent-{{ $agent->backend_id }}"
                    class="group rounded-xl border border-zinc-200 p-5 transition hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:border-zinc-500 dark:hover:bg-white/5"
                >
                    <div class="flex items-start justify-between gap-3">
                        <flux:heading size="lg" class="truncate">{{ $agent->name }}</flux:heading>

                        @if ($agent->is_demo)
                            <flux:badge size="sm" color="amber">Demo</flux:badge>
                        @endif
                    </div>

                    <flux:text class="mt-2 line-clamp-2">
                        {{ $agent->description ?? 'Nessuna descrizione.' }}
                    </flux:text>

                    <flux:text size="sm" class="mt-4 text-zinc-400">
                        Creato {{ $agent->created_at?->diffForHumans() }}
                    </flux:text>
                </a>
            @endforeach
        </div>
    @endif
</section>
