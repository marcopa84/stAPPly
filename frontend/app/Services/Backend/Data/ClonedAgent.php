<?php

namespace App\Services\Backend\Data;

/**
 * Il risultato di una copia: quanti documenti e tabelle sono passati da un agente all'altro.
 */
final readonly class ClonedAgent
{
    /**
     * @param  string  $agentId  l'agente che ha ricevuto la copia
     * @param  string  $from  l'agente copiato
     */
    public function __construct(
        public string $agentId,
        public string $from,
        public int $documents,
        public int $tables,
    ) {}

    /**
     * @param  array{agent_id: string, from: string, documents: int, tables: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            agentId: $data['agent_id'],
            from: $data['from'],
            documents: $data['documents'],
            tables: $data['tables'],
        );
    }
}
