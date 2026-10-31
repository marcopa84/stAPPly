<?php

namespace App\Services\Backend\Data;

/**
 * Un tool chiamato dall'agente durante il turno di chat, con i suoi argomenti.
 */
final readonly class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments  se il modello ha prodotto JSON non valido: ['_raw' => testo]
     */
    public function __construct(
        public string $name,
        public array $arguments,
    ) {}

    /**
     * @param  array{name: string, arguments: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['arguments']);
    }

    /**
     * Gli argomenti da mostrare nella traccia: le stringhe come sono, il resto in JSON.
     *
     * @return array<string, string>
     */
    public function argumentsAsText(): array
    {
        return array_map(
            fn (mixed $value): string => is_string($value)
                ? $value
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->arguments,
        );
    }

    /**
     * La stessa forma del contratto, per salvarlo e rileggerlo con fromArray().
     *
     * @return array{name: string, arguments: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'arguments' => $this->arguments];
    }
}
