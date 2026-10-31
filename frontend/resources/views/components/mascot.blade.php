{{--
    Mascotte di stAPPly: una pinzatrice vista di lato, con una visiera al posto della faccia.
    Lo stato sta solo in data-state e lo stile (colori, pose, animazioni) in resources/css/app.css,
    così chi la usa può cambiarlo anche lato client senza ridisegnarla.

    Uso: <x-mascot state="thinking" class="size-12" />. Con l'attributo decorative è nascosta agli
    screen reader: serve quando accanto c'è già un testo che dice la stessa cosa.
--}}
@props([
    'state' => 'idle',
    'decorative' => false,
])

@php
    $labels = [
        'idle' => 'La pinzatrice di stAPPly, in attesa',
        'thinking' => 'La pinzatrice di stAPPly sta pensando',
        'happy' => 'La pinzatrice di stAPPly è contenta',
        'confused' => 'La pinzatrice di stAPPly è confusa',
    ];

    // Uno stato sconosciuto non deve rompere la pagina: si torna alla posa di riposo.
    $state = array_key_exists($state, $labels) ? $state : 'idle';
@endphp

<svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 128 104"
    data-state="{{ $state }}"
    focusable="false"
    @if ($decorative)
        aria-hidden="true"
    @else
        role="img"
        aria-label="{{ $labels[$state] }}"
    @endif
    {{ $attributes->class('mascot') }}
>
    <ellipse class="mascot-shadow" cx="58" cy="97" rx="46" ry="3" />

    <g class="mascot-body">
        <rect class="mascot-paper" x="62" y="77" width="56" height="3" rx="1" />
        <rect class="mascot-paper" x="67" y="74.5" width="47" height="2.5" rx="1" />

        <rect class="mascot-steel" x="8" y="80" width="96" height="13" rx="6.5" />
        <rect class="mascot-steel" x="14" y="56" width="20" height="28" rx="5" />

        <g class="mascot-head">
            <rect class="mascot-steel" x="36" y="58" width="70" height="7" rx="3.5" />
            <rect class="mascot-shell" x="8" y="34" width="104" height="28" rx="14" />
            <path class="mascot-sheen" d="M24 40.5H54" />
            <rect class="mascot-visor" x="62" y="40" width="40" height="16" rx="8" />

            <g class="mascot-eyes" data-eyes="idle">
                <rect class="mascot-eye" x="71" y="44" width="6" height="8" rx="3" />
                <rect class="mascot-eye" x="87" y="44" width="6" height="8" rx="3" />
            </g>
            <g class="mascot-eyes" data-eyes="thinking">
                <rect class="mascot-eye" x="70" y="45" width="8" height="3" rx="1.5" />
                <rect class="mascot-eye" x="86" y="45" width="8" height="3" rx="1.5" />
            </g>
            <g class="mascot-eyes" data-eyes="happy">
                <path class="mascot-eye-line" d="M70 50.5q4-6 8 0M86 50.5q4-6 8 0" />
            </g>
            <g class="mascot-eyes" data-eyes="confused">
                <circle class="mascot-eye" cx="74" cy="48" r="3" />
                <path class="mascot-eye-line" d="M86.5 49.5l7-3" />
            </g>
        </g>

        <circle class="mascot-metal" cx="24" cy="62" r="3" />

        <path class="mascot-staple" d="M108 30v-8h14v8" />
    </g>
</svg>
