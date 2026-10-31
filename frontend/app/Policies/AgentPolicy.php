<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

/**
 * Ogni agente è visibile solo al suo proprietario. Gli agenti demo sono pronti per la
 * prova: documenti e tabelle li carica lo script del backend, quindi non si toccano e
 * l'agente non si elimina. Le istruzioni invece restano modificabili, perché servono
 * per correggere le risposte della griglia di prova. Le copie degli agenti demo nelle
 * sandbox della demo non sono agenti demo: il visitatore le cambia come vuole.
 */
class AgentPolicy
{
    public function view(User $user, Agent $agent): bool
    {
        return $this->owns($user, $agent);
    }

    public function update(User $user, Agent $agent): bool
    {
        return $this->owns($user, $agent);
    }

    public function delete(User $user, Agent $agent): bool
    {
        return $this->owns($user, $agent) && ! $agent->is_demo;
    }

    /**
     * Caricare ed eliminare documenti e tabelle.
     */
    public function manageFiles(User $user, Agent $agent): bool
    {
        return $this->owns($user, $agent) && ! $agent->is_demo;
    }

    private function owns(User $user, Agent $agent): bool
    {
        return $agent->user()->is($user);
    }
}
