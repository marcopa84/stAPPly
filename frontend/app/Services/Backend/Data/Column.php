<?php

namespace App\Services\Backend\Data;

/**
 * Una colonna di una tabella, con il tipo SQLite dedotto dal backend.
 */
final readonly class Column
{
    public function __construct(
        public string $name,
        public string $type,
    ) {}

    /**
     * @param  array{name: string, type: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['type']);
    }
}
