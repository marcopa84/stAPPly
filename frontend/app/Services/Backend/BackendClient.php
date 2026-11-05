<?php

namespace App\Services\Backend;

use App\Services\Backend\Data\ChatReply;
use App\Services\Backend\Data\ClonedAgent;
use App\Services\Backend\Data\Document;
use App\Services\Backend\Data\SqlResult;
use App\Services\Backend\Data\Table;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Client del backend FastAPI: un metodo per ogni endpoint di docs/api.md.
 *
 * Il backend non conosce gli utenti: lavora per agent_id, che qui è sempre il
 * backend_id dell'agente. Qualunque errore (backend spento, timeout, risposta
 * 4xx o 5xx) arriva al chiamante come BackendException, con un messaggio
 * che si può mostrare all'utente.
 */
class BackendClient
{
    /**
     * Le regex di docs/api.md (le stesse di backend/app/core/ids.py) per gli id che genera
     * il backend. D: senza, "$" accetterebbe anche un "\n" finale.
     */
    public const DOCUMENT_ID_PATTERN = '/^(?=.{1,80}$)[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public const TABLE_PATTERN = '/^[a-z_][a-z0-9_]{0,59}$/D';

    /** Secondi concessi a /health: la dashboard non deve restare appesa a un backend bloccato. */
    private const HEALTH_TIMEOUT = 3;

    /**
     * Secondi concessi a /titles: il backend si ferma da sé a 20. Un titolo che tarda non
     * deve tenere occupato un worker di PHP, e senza resta quello provvisorio.
     */
    private const TITLE_TIMEOUT = 25;

    /** Secondi di PHP oltre il timeout HTTP, per rispondere all'utente dopo la chiamata. */
    private const TIME_LIMIT_MARGIN = 30;

    public function __construct(
        private readonly string $baseUrl,
        #[SensitiveParameter] private readonly string $key,
        private readonly int $timeout,
    ) {}

    /**
     * Vero se il backend risponde e si dichiara in salute.
     */
    public function health(): bool
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->connectTimeout(self::HEALTH_TIMEOUT)
            ->timeout(self::HEALTH_TIMEOUT)
            ->get('health'));

        return $response->json('status') === 'ok';
    }

    /**
     * Carica e indicizza un documento. Un documento con lo stesso document_id viene sostituito.
     */
    public function uploadDocument(string $agentId, UploadedFile $file): Document
    {
        $response = $this->upload($this->path('agents', $agentId, 'documents'), $file);

        return Document::fromArray($response->json());
    }

    /**
     * @return list<Document> ordinati per nome del file
     */
    public function documents(string $agentId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->get($this->path('agents', $agentId, 'documents')));

        return array_values(array_map(Document::fromArray(...), $response->json('documents')));
    }

    public function deleteDocument(string $agentId, string $documentId): void
    {
        $this->send(fn (PendingRequest $http) => $http
            ->delete($this->path('agents', $agentId, 'documents', $documentId)));
    }

    /**
     * Carica un .csv o un .xlsx come tabella. Una tabella con lo stesso nome viene sostituita.
     */
    public function uploadTable(string $agentId, UploadedFile $file): Table
    {
        $response = $this->upload($this->path('agents', $agentId, 'tables'), $file);

        return Table::fromArray($response->json());
    }

    /**
     * @return list<Table> ordinate per nome della tabella
     */
    public function tables(string $agentId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->get($this->path('agents', $agentId, 'tables')));

        return array_values(array_map(Table::fromArray(...), $response->json('tables')));
    }

    public function deleteTable(string $agentId, string $table): void
    {
        $this->send(fn (PendingRequest $http) => $http
            ->delete($this->path('agents', $agentId, 'tables', $table)));
    }

    /**
     * Esegue una SELECT in sola lettura sulle tabelle dell'agente (la stessa di query_db).
     */
    public function sql(string $agentId, string $sql): SqlResult
    {
        $this->extendTimeLimit();

        $response = $this->send(fn (PendingRequest $http) => $http
            ->post($this->path('agents', $agentId, 'sql'), ['sql' => $sql]));

        return SqlResult::fromArray($response->json());
    }

    /**
     * Un turno di chat. Le istruzioni sono quelle salvate in Laravel: il backend
     * aggiunge le regole fisse di stAPPly e ricorda la conversazione per sessione.
     */
    public function chat(string $agentId, string $sessionId, string $message, string $instructions, string $agentName): ChatReply
    {
        $this->extendTimeLimit();

        $response = $this->send(fn (PendingRequest $http) => $http
            ->post($this->path('agents', $agentId, 'chat'), [
                'session_id' => $sessionId,
                'message' => $message,
                'instructions' => $instructions,
                'agent_name' => $agentName,
            ]));

        return ChatReply::fromArray($response->json());
    }

    /**
     * Il titolo di una chat, scritto dal modello a partire dal primo scambio.
     */
    public function title(string $message, string $answer): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->timeout(self::TITLE_TIMEOUT)
            ->post($this->path('titles'), ['message' => $message, 'answer' => $answer]));

        $title = $response->json('title');

        if (! is_string($title) || trim($title) === '') {
            throw new BackendException('Il backend non ha scritto un titolo per la chat.', $response->status());
        }

        return trim($title);
    }

    /**
     * Dimentica una conversazione. Va bene anche se la sessione non esisteva.
     */
    public function deleteSession(string $agentId, string $sessionId): void
    {
        $this->send(fn (PendingRequest $http) => $http
            ->delete($this->path('agents', $agentId, 'sessions', $sessionId)));
    }

    /**
     * Copia in $agentId documenti e tabelle di $from, con i vettori già calcolati: niente
     * embedding nuovi, quindi niente costi. Serve alle sandbox della demo. $agentId non
     * deve avere dati: la copia non sovrascrive (409).
     */
    public function cloneAgent(string $agentId, string $from): ClonedAgent
    {
        $this->extendTimeLimit();

        $response = $this->send(fn (PendingRequest $http) => $http
            ->post($this->path('agents', $agentId, 'clone'), ['from' => $from]));

        return ClonedAgent::fromArray($response->json());
    }

    /**
     * Cancella documenti, tabelle e sessioni dell'agente. Va bene anche se non aveva dati.
     */
    public function deleteAgent(string $agentId): void
    {
        $this->send(fn (PendingRequest $http) => $http
            ->delete($this->path('agents', $agentId)));
    }

    /**
     * Invia il file come multipart `file`, con il nome originale: il backend ne
     * ricava il document_id o il nome della tabella.
     */
    private function upload(string $path, UploadedFile $file): Response
    {
        $this->extendTimeLimit();

        return $this->send(fn (PendingRequest $http) => $http
            ->attach('file', Utils::tryFopen($file->getPathname(), 'r'), $file->getClientOriginalName())
            ->post($path));
    }

    /**
     * Una richiesta nuova a ogni chiamata: la PendingRequest tiene stato e il
     * client è un singleton. Il backend non reindirizza mai: un redirect seguito
     * porterebbe la chiave interna, e il metodo, su un altro percorso.
     */
    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-Internal-Key' => $this->key])
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout($this->timeout);
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws BackendException
     */
    private function send(callable $request): Response
    {
        try {
            $response = $request($this->http());
        } catch (ConnectionException $e) {
            Log::warning('Backend di stAPPly non raggiungibile.', ['error' => $e->getMessage()]);

            throw BackendException::unreachable($e);
        }

        if ($response->unauthorized()) {
            Log::error('Il backend ha rifiutato X-Internal-Key: STAPPLY_BACKEND_KEY deve coincidere con INTERNAL_API_KEY del backend.');

            throw BackendException::unauthorized();
        }

        if ($response->serverError()) {
            Log::warning('Errore del backend di stAPPly.', [
                'status' => $response->status(),
                'url' => (string) $response->effectiveUri(),
                'detail' => $response->json('detail'),
            ]);
        }

        // Anche un 3xx: con i redirect spenti arriverebbe qui come un successo.
        if (! $response->successful()) {
            throw BackendException::fromResponse($response);
        }

        return $response;
    }

    /**
     * Un segmento per parametro, codificato. "." e ".." restano tali anche codificati, e
     * il client HTTP li risolve: DELETE agents/x/tables/.. diventerebbe DELETE agents/x,
     * cioè tutti i dati dell'agente. Vuoti non hanno senso: si fermano qui.
     *
     * @throws InvalidArgumentException
     */
    private function path(string ...$segments): string
    {
        foreach ($segments as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                throw new InvalidArgumentException("Segmento non valido nel percorso del backend: «{$segment}».");
            }
        }

        return implode('/', array_map(rawurlencode(...), $segments));
    }

    /**
     * Sotto FPM PHP si ferma dopo 30 secondi, meno del timeout del client: prima
     * di una chiamata lunga (embedding, più passaggi col modello) il limite si
     * allunga. Se è già spento, come da riga di comando, resta spento.
     */
    private function extendTimeLimit(): void
    {
        if ((int) ini_get('max_execution_time') !== 0) {
            set_time_limit($this->timeout + self::TIME_LIMIT_MARGIN);
        }
    }
}
