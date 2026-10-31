{{-- Solo per le sandbox della demo: cosa si può fare e fino a quando. --}}
@php($user = auth()->user())

@if (\App\Support\DemoAccount::isSandbox($user))
    <flux:callout variant="secondary" icon="beaker" class="mb-6" data-test="demo-sandbox-notice">
        <flux:callout.heading>Sei nella demo: puoi modificare tutto. Al prossimo accesso riparti dai valori iniziali.</flux:callout.heading>
        <flux:callout.text>
            {{-- L'ora arriva dal server in UTC: il browser la riscrive nel fuso del visitatore. --}}
            Questa demo è tua fino alle
            <time
                datetime="{{ $user->demo_expires_at->toIso8601String() }}"
                x-data
                x-text="new Date($el.getAttribute('datetime')).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' })"
            >{{ $user->demo_expires_at->format('H:i') }} UTC</time>;
            uscendo la chiudi subito.
        </flux:callout.text>
    </flux:callout>
@endif
