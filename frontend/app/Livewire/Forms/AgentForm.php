<?php

namespace App\Livewire\Forms;

use App\Models\Agent;
use App\Models\User;
use Livewire\Form;

/**
 * Nome, descrizione e istruzioni di un agente, condivisi da creazione e modifica.
 * I limiti sono quelli della chat del backend: istruzioni salvate qui e rifiutate
 * dopo, a ogni messaggio, sarebbero un errore difficile da capire.
 */
class AgentForm extends Form
{
    public string $name = '';

    public string $description = '';

    public string $instructions = '';

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'instructions' => ['required', 'string', 'max:8000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.required' => "Dai un nome all'agente.",
            'name.max' => 'Il nome può avere al massimo 100 caratteri.',
            'description.max' => 'La descrizione può avere al massimo 255 caratteri.',
            'instructions.required' => "Scrivi le istruzioni: chi è l'agente e di cosa si occupa.",
            'instructions.max' => 'Le istruzioni possono avere al massimo 8000 caratteri.',
        ];
    }

    public function fillFrom(Agent $agent): void
    {
        $this->name = $agent->name;
        $this->description = $agent->description ?? '';
        $this->instructions = $agent->instructions;
    }

    public function store(User $owner): Agent
    {
        return $owner->agents()->create($this->validated());
    }

    public function update(Agent $agent): void
    {
        $agent->update($this->validated());
    }

    /**
     * @return array{name: string, description: string|null, instructions: string}
     */
    private function validated(): array
    {
        $this->validate();

        return [
            'name' => trim($this->name),
            'description' => trim($this->description) ?: null,
            'instructions' => trim($this->instructions),
        ];
    }
}
