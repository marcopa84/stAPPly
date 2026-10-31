<?php

namespace App\Livewire\Agents;

use App\Livewire\Forms\AgentForm;
use App\Support\UsageLimits;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read string|null $limitReason
 */
#[Title('Nuovo agente')]
class Create extends Component
{
    public AgentForm $form;

    /**
     * Perché non si può creare un altro agente (il limite per utente, più basso nelle sandbox demo), o null.
     */
    #[Computed]
    public function limitReason(): ?string
    {
        return UsageLimits::agentLimitReason(Auth::user());
    }

    public function save(): void
    {
        // La pagina lo dice già, ma il limite vale anche per chi chiama l'azione a mano.
        if ($this->limitReason !== null) {
            return;
        }

        $agent = $this->form->store(Auth::user());

        $this->redirectRoute('agents.show', $agent, navigate: true);
    }
}
