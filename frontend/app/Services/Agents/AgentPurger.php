<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Services\Backend\BackendClient;
use App\Services\Backend\BackendException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Cancella un agente per intero: prima documenti, tabelle e sessioni nel backend,
 * poi l'agente qui, con chat e messaggi. Se il backend non risponde l'agente resta,
 * così nel backend non restano dati senza più un proprietario e si può riprovare.
 *
 * È l'unico punto che chiama DELETE /agents/{agent_id}: lo usano l'eliminazione di
 * un agente, quella dell'account e la pulizia delle sandbox della demo.
 */
class AgentPurger
{
    public function __construct(private readonly BackendClient $backend) {}

    /**
     * @throws ProtectedAgentException se è un agente demo o del template
     * @throws BackendException se il backend non lo cancella: l'agente resta
     */
    public function purge(Agent $agent): void
    {
        if ($this->isProtected($agent)) {
            throw new ProtectedAgentException($agent);
        }

        $this->backend->deleteAgent($agent->backend_id);

        $agent->delete();
    }

    /**
     * Ultima difesa prima di una DELETE: un agente demo o del template non si cancella
     * mai, qualunque errore ci sia nel resto del codice. Si controlla sul DB, non su un
     * elenco di id scritto a mano.
     */
    private function isProtected(Agent $agent): bool
    {
        return Agent::query()
            ->where('backend_id', $agent->backend_id)
            ->where(fn (Builder $query) => $query
                ->where('is_demo', true)
                ->orWhereHas('user', fn (Builder $owner) => $owner
                    ->where('email', Str::lower(Config::string('stapply.demo.email')))
                    ->whereNull('demo_expires_at')))
            ->exists();
    }
}
