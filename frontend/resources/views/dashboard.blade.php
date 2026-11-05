<div class="flex w-full flex-col gap-8">
    <div class="flex items-center gap-4 sm:gap-6">
        <x-mascot state="idle" decorative class="w-20 shrink-0 sm:w-28" />

        <div class="min-w-0">
            <flux:heading size="xl" level="1">Ciao, {{ auth()->user()->name }}</flux:heading>
            <flux:subheading size="lg">Staple your docs. Apply your knowledge.</flux:subheading>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="flex items-center justify-between gap-2">
                <flux:heading class="flex items-center gap-2">
                    <flux:icon.server-stack variant="mini" class="text-zinc-400" /> Backend
                </flux:heading>

                @if ($backendOnline)
                    <flux:badge color="green" size="sm" icon="check-circle">In linea</flux:badge>
                @else
                    <flux:badge color="red" size="sm" icon="exclamation-triangle">Non raggiungibile</flux:badge>
                @endif
            </div>

            @if ($backendOnline)
                <flux:text class="mt-3">Caricamento dei file, ricerca e chat sono disponibili.</flux:text>
            @else
                <flux:text class="mt-3">{{ $backendError }}</flux:text>
                <flux:text size="sm" class="mt-2">
                    Intanto puoi creare agenti e modificarne le istruzioni; per caricare file e chattare serve il backend.
                </flux:text>
            @endif
        </div>

        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading class="flex items-center gap-2">
                <flux:icon.cpu-chip variant="mini" class="text-zinc-400" /> Agenti
            </flux:heading>

            <p class="mt-3 text-3xl font-semibold text-zinc-800 dark:text-white">{{ $agents->count() }}</p>

            <flux:button size="sm" icon="plus" class="mt-3" :href="route('agents.create')" wire:navigate>
                Nuovo agente
            </flux:button>
        </div>

        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading class="flex items-center gap-2">
                <flux:icon.paper-clip variant="mini" class="text-zinc-400" /> Come funziona
            </flux:heading>

            <ol class="mt-3 list-decimal space-y-1 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                <li>Crea un agente e scrivi di cosa si occupa.</li>
                <li>Caricagli documenti e tabelle.</li>
                <li>Fagli domande: risponde solo da lì e cita il passaggio.</li>
            </ol>
        </div>
    </div>

    <section>
        <flux:heading size="lg" class="mb-4">Riprendi una chat</flux:heading>

        @if ($conversations->isEmpty())
            <flux:text>
                Non hai ancora chat. Apri un agente e scegli «Chat»: ogni chat resta nello storico, e riaprendola l'agente ricorda lo scambio.
            </flux:text>
        @else
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($conversations as $conversation)
                    <a
                        href="{{ route('agents.chat', ['agent' => $conversation->agent, 'conversation' => $conversation]) }}"
                        wire:navigate
                        wire:key="dashboard-conversation-{{ $conversation->session_id }}"
                        class="flex items-start gap-3 rounded-lg border border-zinc-200 px-4 py-3 transition hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500"
                    >
                        <flux:icon.chat-bubble-left-right variant="mini" class="mt-0.5 shrink-0 text-zinc-400" />

                        <span class="min-w-0">
                            <span class="block truncate font-medium text-zinc-800 dark:text-white">{{ $conversation->title }}</span>
                            <span class="block truncate text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $conversation->agent->name }}, {{ $conversation->updated_at?->diffForHumans() }}
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section>
        <div class="mb-4 flex items-center justify-between gap-4">
            <flux:heading size="lg">I tuoi agenti</flux:heading>

            @if ($agents->isNotEmpty())
                <flux:link :href="route('agents.index')" wire:navigate class="text-sm">Vedi tutti</flux:link>
            @endif
        </div>

        @if ($agents->isEmpty())
            <flux:text>
                Non hai ancora agenti.
                <flux:link :href="route('agents.create')" wire:navigate>Crea il primo</flux:link>
                e caricagli i tuoi documenti.
            </flux:text>
        @else
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($agents->take(6) as $agent)
                    <a
                        href="{{ route('agents.show', $agent) }}"
                        wire:navigate
                        wire:key="dashboard-agent-{{ $agent->backend_id }}"
                        class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-4 py-3 transition hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500"
                    >
                        <span class="truncate font-medium text-zinc-800 dark:text-white">{{ $agent->name }}</span>

                        @if ($agent->is_demo)
                            <flux:badge size="sm" color="amber">Demo</flux:badge>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</div>
