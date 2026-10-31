<?php

namespace App\Services\Backend;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Errore nel parlare con il backend. Il messaggio si può mostrare all'utente così com'è.
 */
class BackendException extends RuntimeException
{
    /**
     * @param  int|null  $status  codice HTTP della risposta; null se il backend non ha risposto
     */
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function unreachable(ConnectionException $previous): self
    {
        return new self(
            'Il backend non è raggiungibile o non ha risposto in tempo: riprova tra poco.',
            previous: $previous,
        );
    }

    /**
     * Chiave interna rifiutata: è un problema di configurazione, non dell'utente,
     * e il messaggio non deve dire nulla della chiave.
     */
    public static function unauthorized(): self
    {
        return new self(
            "Il backend non ha accettato le credenziali di stAPPly. È un problema di configurazione: avvisa l'amministratore.",
            401,
        );
    }

    /**
     * Il backend scrive in `detail` un messaggio in italiano per l'utente. Fanno eccezione
     * i 422 di validazione di FastAPI (una lista di oggetti) e le risposte che non sono JSON.
     */
    public static function fromResponse(Response $response): self
    {
        $detail = $response->json('detail');

        $message = is_string($detail) && $detail !== ''
            ? $detail
            : "Il backend ha risposto con un errore inatteso (codice {$response->status()}).";

        return new self($message, $response->status());
    }
}
