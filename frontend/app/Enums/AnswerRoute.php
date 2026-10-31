<?php

namespace App\Enums;

/**
 * La strada di una risposta, calcolata dal backend dai tool chiamati nel turno.
 * Nella traccia sotto la risposta è un badge: i criteri 3 e 4 di CONSEGNA.md si
 * verificano da lì (fuori tema senza tool, domanda mista con tutti e due i tool).
 */
enum AnswerRoute: string
{
    case Documents = 'documenti';
    case Data = 'dati';
    case Both = 'documenti+dati';
    case None = 'nessuna';

    /**
     * Il colore del badge Flux: uno diverso per strada, così la griglia di prova si legge a colpo d'occhio.
     */
    public function color(): string
    {
        return match ($this) {
            self::Documents => 'sky',
            self::Data => 'orange',
            self::Both => 'violet',
            self::None => 'zinc',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Documents => 'document-text',
            self::Data => 'table-cells',
            self::Both => 'squares-plus',
            self::None => 'no-symbol',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Documents => 'Ha cercato nei documenti.',
            self::Data => 'Ha interrogato le tabelle.',
            self::Both => 'Ha usato documenti e tabelle.',
            self::None => 'Ha risposto senza chiamare tool.',
        };
    }
}
