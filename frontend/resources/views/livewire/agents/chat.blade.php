<section class="w-full">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('agents.index')" wire:navigate>Agenti</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('agents.show', $agent)" wire:navigate>{{ $agent->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Chat</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex items-center gap-3">
                <flux:heading size="xl" level="1" class="truncate">Chat con {{ $agent->name }}</flux:heading>

                @if ($agent->is_demo)
                    <flux:badge size="sm" color="amber">Demo</flux:badge>
                @endif
            </div>

            <flux:subheading size="lg">
                Risponde solo da documenti e tabelle dell'agente. Sotto ogni risposta c'è la traccia: strada e tool chiamati.
            </flux:subheading>
        </div>

        <flux:button icon="document-text" :href="route('agents.show', $agent)" wire:navigate>
            Documenti e tabelle
        </flux:button>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6" :heading="session('status')" />
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <aside class="space-y-6">
            <form wire:submit="open">
                <flux:field>
                    <flux:label>Nuova chat</flux:label>

                    <div class="flex gap-2">
                        <flux:input wire:model="title" maxlength="100" placeholder="Ferie 2026" />
                        <flux:button type="submit" icon="plus">Apri</flux:button>
                    </div>

                    <flux:error name="title" />

                    <flux:description>Un nome già usato riapre quella chat: l'agente ricorda lo scambio.</flux:description>
                </flux:field>
            </form>

            <nav aria-label="Le tue chat con {{ $agent->name }}" class="space-y-1">
                <flux:heading size="sm" class="mb-2">Le tue chat</flux:heading>

                @forelse ($conversations as $item)
                    @php($isOpen = $item->is($conversation))

                    <a
                        href="{{ route('agents.chat', ['agent' => $agent, 'conversation' => $item]) }}"
                        wire:navigate
                        wire:key="conversation-{{ $item->session_id }}"
                        @if ($isOpen) aria-current="page" @endif
                        @class([
                            'flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm transition',
                            'border-zinc-200 bg-white font-medium text-accent-content dark:border-transparent dark:bg-white/[7%]' => $isOpen,
                            'border-transparent text-zinc-600 hover:bg-zinc-800/5 hover:text-zinc-800 dark:text-white/80 dark:hover:bg-white/[7%] dark:hover:text-white' => ! $isOpen,
                        ])
                    >
                        <span class="truncate">{{ $item->title }}</span>
                        <span class="shrink-0 text-xs text-zinc-400">{{ $item->updated_at?->diffForHumans(short: true) }}</span>
                    </a>
                @empty
                    <flux:text size="sm">Non hai ancora chat con questo agente.</flux:text>
                @endforelse
            </nav>
        </aside>

        <div class="min-w-0">
            @if ($conversation)
                <div class="flex flex-col rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <flux:heading size="lg" level="2" class="truncate">{{ $conversation->title }}</flux:heading>

                        <flux:modal.trigger name="delete-conversation">
                            <flux:button size="sm" variant="ghost" icon="trash">Elimina</flux:button>
                        </flux:modal.trigger>
                    </div>

                    @if ($messages->isEmpty())
                        <div class="flex flex-col items-center gap-3 px-4 py-12 text-center">
                            <x-mascot state="idle" class="w-28" />
                            <flux:text>Fai la prima domanda a {{ $agent->name }}.</flux:text>
                        </div>
                    @else
                        {{-- column-reverse tiene lo scorrimento in fondo: l'ultimo messaggio resta in vista senza JavaScript. --}}
                        <div class="flex max-h-[70dvh] min-h-72 flex-col-reverse overflow-y-auto lg:max-h-[calc(100dvh-24rem)]">
                            <div class="space-y-6 p-4" role="log" aria-label="Messaggi della chat">
                                @foreach ($messages as $item)
                                    @if ($item->isFromUser())
                                        <div class="flex flex-col items-end gap-1" wire:key="message-{{ $item->id }}">
                                            <div class="max-w-[85%] whitespace-pre-line break-words rounded-2xl rounded-br-sm bg-accent/10 px-4 py-2 text-zinc-800 dark:text-zinc-100">{{ $item->content }}</div>

                                            @if (! $loop->last && $messages[$loop->index + 1]->isFromUser())
                                                <flux:text size="sm">Senza risposta</flux:text>
                                            @endif
                                        </div>
                                    @else
                                        <div class="flex items-start gap-3" wire:key="message-{{ $item->id }}">
                                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                                                <x-app-logo-icon class="size-5" aria-hidden="true" />
                                            </div>

                                            <div class="min-w-0 flex-1">
                                                <div class="chat-answer">{{ $item->answerHtml() }}</div>

                                                @include('livewire.agents.partials.trace', ['reply' => $item])
                                            </div>
                                        </div>
                                    @endif
                                @endforeach

                                <div wire:loading.flex wire:target="reply" class="items-center gap-3" role="status">
                                    <x-mascot state="thinking" decorative class="w-16 shrink-0" />
                                    <flux:text>{{ $agent->name }} sta cercando la risposta…</flux:text>
                                </div>

                                @if ($pending && ! $awaitingReply)
                                    <div class="flex items-start gap-3" wire:loading.remove wire:target="retry, reply">
                                        <x-mascot :state="$error ? 'confused' : 'idle'" decorative class="w-16 shrink-0" />

                                        <flux:callout :variant="$error ? 'danger' : 'warning'" icon="exclamation-triangle" class="flex-1">
                                            <flux:callout.heading>
                                                {{ $error ? 'La risposta non è arrivata' : 'Questa domanda è rimasta senza risposta' }}
                                            </flux:callout.heading>
                                            <flux:callout.text>
                                                {{ $error ?? "Puoi rimandarla all'agente." }}
                                            </flux:callout.text>

                                            <x-slot name="actions">
                                                <flux:button size="sm" icon="arrow-path" wire:click="retry">Riprova</flux:button>
                                            </x-slot>
                                        </flux:callout>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <form wire:submit="send" class="border-t border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="flex items-end gap-2">
                            <div class="flex-1">
                                <flux:textarea
                                    wire:model="message"
                                    rows="2"
                                    resize="none"
                                    maxlength="4000"
                                    placeholder="Scrivi una domanda…"
                                    aria-label="Messaggio per {{ $agent->name }}"
                                    wire:loading.attr="disabled"
                                    wire:target="send, reply, retry"
                                    x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); $el.form.requestSubmit() }"
                                />
                            </div>

                            <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="send, reply, retry">
                                Invia
                            </flux:button>
                        </div>

                        <flux:error name="message" class="mt-2" />

                        <flux:text size="sm" class="mt-2 hidden sm:block">Invio per mandare, Maiusc+Invio per andare a capo.</flux:text>
                    </form>
                </div>

                <flux:modal name="delete-conversation" @close="$set('deleteError', null)" focusable class="max-w-lg">
                    <div class="space-y-6">
                        <div>
                            <flux:heading size="lg">Eliminare la chat «{{ $conversation->title }}»?</flux:heading>
                            <flux:text class="mt-2">
                                I messaggi spariscono e l'agente dimentica la conversazione: una chat nuova con lo stesso nome ripartirà da zero.
                            </flux:text>
                        </div>

                        @if ($deleteError)
                            <flux:callout variant="danger" icon="exclamation-triangle" heading="La chat non è stata eliminata" :text="$deleteError" />
                        @endif

                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="filled">Annulla</flux:button>
                            </flux:modal.close>

                            <flux:button variant="danger" wire:click="delete">Elimina</flux:button>
                        </div>
                    </div>
                </flux:modal>
            @else
                <div class="flex flex-col items-center gap-4 rounded-xl border border-dashed border-zinc-300 px-6 py-12 text-center dark:border-zinc-600">
                    <x-mascot state="idle" class="w-32" />
                    <flux:heading size="lg">Scegli una chat o iniziane una nuova</flux:heading>
                    <flux:text class="max-w-md">
                        Dai un nome alla chat, per esempio «Ferie 2026». Se la riapri con lo stesso nome,
                        {{ $agent->name }} ricorda quello che vi siete detti.
                    </flux:text>
                </div>
            @endif
        </div>
    </div>
</section>
