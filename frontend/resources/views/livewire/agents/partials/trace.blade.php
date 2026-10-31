{{--
    La traccia di una risposta: la strada, i tool chiamati con i loro argomenti e l'avviso se
    l'agente si è fermato prima di rispondere. Da qui si verificano i criteri 3 e 4 di
    CONSEGNA.md. Variabile: $reply (un Message dell'agente).
--}}
@php
    $route = $reply->answerRoute();
    $tools = $reply->toolCalls();
@endphp

<div class="mt-3 space-y-3 rounded-lg bg-zinc-50 p-3 text-sm dark:bg-white/5" data-trace>
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">Strada</span>

        <flux:badge
            size="sm"
            :color="$route?->color() ?? 'red'"
            :icon="$route?->icon() ?? 'question-mark-circle'"
            title="{{ $route?->description() ?? 'Strada non prevista dal contratto API.' }}"
        >
            {{ $reply->route }}
        </flux:badge>

        <span class="text-xs text-zinc-500 dark:text-zinc-400">
            {{ $route?->description() }}
        </span>
    </div>

    @if ($reply->reachedMaxTurns())
        <flux:callout
            variant="warning"
            icon="exclamation-triangle"
            heading="L'agente si è fermato prima di rispondere"
            text="Ha raggiunto il numero massimo di passaggi, quindi la risposta non viene dai tool. Prova a riformulare la domanda."
        />
    @endif

    @if ($tools === [])
        <p class="text-zinc-500 dark:text-zinc-400">Nessun tool chiamato.</p>
    @else
        <ol class="space-y-2">
            @foreach ($tools as $tool)
                <li class="space-y-1" wire:key="trace-{{ $reply->id }}-{{ $loop->index }}">
                    <code class="font-mono text-xs font-semibold text-zinc-800 dark:text-zinc-100">{{ $tool->name }}</code>

                    @foreach ($tool->argumentsAsText() as $name => $value)
                        @if ($name === 'sql')
                            <pre class="overflow-x-auto rounded-md bg-zinc-900 p-2 font-mono text-xs whitespace-pre-wrap text-zinc-100"><code>{{ $value }}</code></pre>
                        @else
                            <p class="break-words text-zinc-600 dark:text-zinc-300">
                                <span class="text-zinc-400">{{ $name === '_raw' ? 'argomenti non validi' : $name }}:</span>
                                {{ $value }}
                            </p>
                        @endif
                    @endforeach
                </li>
            @endforeach
        </ol>
    @endif
</div>
