<?php

namespace App\Livewire\Agents;

use App\Models\Agent;
use App\Services\Backend\BackendClient;
use App\Services\Backend\BackendException;
use App\Services\Backend\Data\SqlResult;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * La console SQL dell'agente: la stessa esecuzione in sola lettura di query_db, per
 * controllare a mano i numeri delle risposte (criterio 4 di CONSEGNA.md). Va anche con
 * gli agenti demo, perché non modifica niente: basta poter vedere l'agente.
 */
class SqlConsole extends Component
{
    #[Locked]
    public Agent $agent;

    public string $sql = '';

    /** Risultato ed errore dell'ultima esecuzione: servono solo alla risposta che li mostra. */
    protected ?SqlResult $result = null;

    protected ?string $error = null;

    public function mount(Agent $agent): void
    {
        $this->authorize('view', $agent);

        $this->agent = $agent;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'sql' => ['required', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'sql.required' => 'Scrivi una query, per esempio una SELECT su una delle tabelle.',
            'sql.max' => 'La query può avere al massimo 10000 caratteri.',
        ];
    }

    /**
     * Il backend rifiuta tutto quello che non è una SELECT: il motivo compare sotto la query.
     */
    public function run(BackendClient $backend): void
    {
        $this->authorize('view', $this->agent);

        $this->sql = trim($this->sql);
        $this->validate();

        try {
            $this->result = $backend->sql($this->agent->backend_id, $this->sql);
        } catch (BackendException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * Lo schema accanto alla console si legge dal backend a ogni richiesta, come le
     * sezioni Documenti e Tabelle. Senza tabelle la console non compare.
     */
    public function render(BackendClient $backend): View
    {
        try {
            $tables = $backend->tables($this->agent->backend_id);
            $loadError = null;
        } catch (BackendException $e) {
            $tables = [];
            $loadError = $e->getMessage();
        }

        return view('livewire.agents.sql-console', [
            'tables' => $tables,
            'loadError' => $loadError,
            'result' => $this->result,
            'error' => $this->error,
        ]);
    }
}
