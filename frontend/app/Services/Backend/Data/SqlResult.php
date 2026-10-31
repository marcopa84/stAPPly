<?php

namespace App\Services\Backend\Data;

/**
 * Il risultato di una SELECT eseguita in sola lettura sul database dell'agente.
 */
final readonly class SqlResult
{
    /**
     * @param  list<string>  $columns
     * @param  list<list<scalar|null>>  $rows
     * @param  bool  $truncated  il backend ha tagliato il risultato al numero massimo di righe
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public bool $truncated,
    ) {}

    /**
     * @param  array{columns: list<string>, rows: list<list<scalar|null>>, truncated: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['columns'], $data['rows'], $data['truncated']);
    }

    /**
     * Vero se la colonna contiene solo numeri, a parte i NULL: nella tabella va allineata a destra.
     */
    public function isNumeric(int $column): bool
    {
        $values = array_filter(array_column($this->rows, $column), fn (mixed $value) => $value !== null);

        foreach ($values as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return false;
            }
        }

        return $values !== [];
    }
}
