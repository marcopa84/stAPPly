<?php

namespace App\Livewire\Agents;

use App\Livewire\Forms\AgentForm;
use App\Models\Agent;
use App\Services\Agents\AgentPurger;
use App\Services\Backend\BackendException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Edit extends Component
{
    #[Locked]
    public Agent $agent;

    public AgentForm $form;

    public ?string $deleteError = null;

    public function mount(Agent $agent): void
    {
        $this->authorize('update', $agent);

        $this->agent = $agent;
        $this->form->fillFrom($agent);
    }

    public function save(): void
    {
        $this->authorize('update', $this->agent);

        $this->form->update($this->agent);

        Flux::toast(variant: 'success', text: 'Agente salvato.');
    }

    /**
     * Prima i dati nel backend, poi l'agente (AgentPurger): se il backend non risponde
     * l'agente resta, e il motivo compare nel modal.
     */
    public function delete(AgentPurger $agents): void
    {
        $this->authorize('delete', $this->agent);

        try {
            $agents->purge($this->agent);
        } catch (BackendException $e) {
            $this->deleteError = $e->getMessage();

            return;
        }

        session()->flash('status', "Agente «{$this->agent->name}» eliminato.");

        $this->redirectRoute('agents.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.agents.edit')->title("Modifica {$this->agent->name}");
    }
}
