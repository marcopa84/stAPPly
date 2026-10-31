<?php

namespace App\Livewire\Agents;

use App\Models\Agent;
use App\Services\Backend\BackendClient;
use App\Services\Backend\BackendException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * La pagina dell'agente: intestazione, navigazione tra le sezioni e la sezione scelta.
 *
 * Ogni sezione è un componente Livewire a sé che riceve l'agente e si autorizza da
 * solo; per aggiungerne una basta una voce in SECTIONS. La chat ha una pagina sua
 * (agents.chat), raggiungibile dal pulsante nell'intestazione.
 *
 * @property-read array<string, array{label: string, icon: string, component: string, needsTables?: bool}> $sections
 */
class Show extends Component
{
    /**
     * Le sezioni, nell'ordine della navigazione. La chiave è il valore di ?section= nell'URL;
     * con needsTables la sezione compare solo se l'agente ha almeno una tabella.
     *
     * @var array<string, array{label: string, icon: string, component: string, needsTables?: bool}>
     */
    public const SECTIONS = [
        'documenti' => ['label' => 'Documenti', 'icon' => 'document-text', 'component' => 'agents.documents'],
        'tabelle' => ['label' => 'Tabelle', 'icon' => 'table-cells', 'component' => 'agents.tables'],
        'sql' => ['label' => 'Console SQL', 'icon' => 'command-line', 'component' => 'agents.sql-console', 'needsTables' => true],
    ];

    public const DEFAULT_SECTION = 'documenti';

    #[Locked]
    public Agent $agent;

    #[Locked]
    #[Url(except: self::DEFAULT_SECTION)]
    public string $section = self::DEFAULT_SECTION;

    public function mount(Agent $agent): void
    {
        $this->authorize('view', $agent);

        $this->agent = $agent;

        if (! array_key_exists($this->section, $this->sections)) {
            $this->section = self::DEFAULT_SECTION;
        }
    }

    /**
     * Le sezioni da mostrare: la console SQL solo se c'è qualcosa da interrogare.
     *
     * @return array<string, array{label: string, icon: string, component: string, needsTables?: bool}>
     */
    #[Computed]
    public function sections(): array
    {
        return array_filter(
            self::SECTIONS,
            fn (array $section): bool => ! ($section['needsTables'] ?? false) || $this->hasTables(),
        );
    }

    public function sectionUrl(string $section): string
    {
        return route('agents.show', $section === self::DEFAULT_SECTION
            ? $this->agent
            : ['agent' => $this->agent, 'section' => $section]);
    }

    public function render(): View
    {
        return view('livewire.agents.show', [
            'sections' => $this->sections,
            'current' => self::SECTIONS[$this->section],
        ])->title($this->agent->name);
    }

    /**
     * Se il backend non risponde la console resta nascosta: non potrebbe eseguire niente,
     * e il motivo lo dicono già le sezioni Documenti e Tabelle.
     */
    private function hasTables(): bool
    {
        try {
            return app(BackendClient::class)->tables($this->agent->backend_id) !== [];
        } catch (BackendException) {
            return false;
        }
    }
}
