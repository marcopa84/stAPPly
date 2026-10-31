<?php

namespace App\Services\Backend\Data;

/**
 * La risposta dell'agente a un messaggio, con la traccia dei tool che ha usato.
 */
final readonly class ChatReply
{
    /** L'agente si è fermato dopo MAX_TURNS passaggi: `answer` è un messaggio fisso che lo dice. */
    public const STATUS_MAX_TURNS = 'max_turns';

    /**
     * @param  string  $status  `ok`, oppure `max_turns` se l'agente si è fermato prima di rispondere
     * @param  string  $route  `documenti`, `dati`, `documenti+dati` o `nessuna`
     * @param  list<ToolCall>  $tools  in ordine di chiamata
     * @param  list<Source>  $sources
     */
    public function __construct(
        public string $answer,
        public string $status,
        public string $route,
        public array $tools,
        public array $sources,
    ) {}

    /**
     * @param  array{answer: string, status: string, route: string, tools: list<array{name: string, arguments: array<string, mixed>}>, sources: list<array{id: string, document_id: string, filename: string, text: string}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            answer: $data['answer'],
            status: $data['status'],
            route: $data['route'],
            tools: array_map(ToolCall::fromArray(...), $data['tools']),
            sources: array_map(Source::fromArray(...), $data['sources']),
        );
    }

    public function reachedMaxTurns(): bool
    {
        return $this->status === self::STATUS_MAX_TURNS;
    }
}
