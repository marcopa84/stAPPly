<?php

namespace App\Services\Backend\Data;

use Carbon\CarbonImmutable;

/**
 * Una tabella del database SQLite dell'agente, nata da un file .csv o .xlsx.
 */
final readonly class Table
{
    /**
     * @param  string  $name  nome della tabella in SQL (campo `table` del contratto)
     * @param  list<Column>  $columns
     * @param  bool  $replaced  solo nella risposta dell'upload: il file ha sostituito una tabella con lo stesso nome
     */
    public function __construct(
        public string $name,
        public string $filename,
        public int $rows,
        public array $columns,
        public CarbonImmutable $uploadedAt,
        public bool $replaced = false,
    ) {}

    /**
     * @param  array{table: string, filename: string, rows: int, columns: list<array{name: string, type: string}>, uploaded_at: string, replaced?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['table'],
            filename: $data['filename'],
            rows: $data['rows'],
            columns: array_map(Column::fromArray(...), $data['columns']),
            uploadedAt: CarbonImmutable::parse($data['uploaded_at']),
            replaced: $data['replaced'] ?? false,
        );
    }
}
