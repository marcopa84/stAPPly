<?php

namespace App\Services\Agents;

use App\Models\Agent;
use LogicException;

/**
 * Qualcuno ha chiesto di cancellare un agente demo o del template: è un errore nel
 * codice, non dell'utente, e la cancellazione non parte.
 */
class ProtectedAgentException extends LogicException
{
    public function __construct(public readonly Agent $agent)
    {
        parent::__construct("L'agente {$agent->backend_id} è un agente demo o del template: non si cancella.");
    }
}
