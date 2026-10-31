<?php

namespace App\Services\Demo;

use RuntimeException;
use Throwable;

/**
 * La sandbox demo non si può aprire adesso. Il messaggio si mostra al visitatore così com'è.
 */
class DemoUnavailableException extends RuntimeException
{
    public static function full(): self
    {
        return new self('In questo momento la demo è piena: riprova più tardi.');
    }

    public static function backend(Throwable $previous): self
    {
        return new self(
            'La demo non è disponibile in questo momento: non riesco a preparare la tua copia degli agenti. Riprova tra qualche minuto.',
            previous: $previous,
        );
    }
}
