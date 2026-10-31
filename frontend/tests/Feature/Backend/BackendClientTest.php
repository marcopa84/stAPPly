<?php

use App\Services\Backend\BackendClient;
use App\Services\Backend\BackendException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->backend = app(BackendClient::class);
});

function sentWithKey(Request $request): bool
{
    return $request->hasHeader('X-Internal-Key', 'chiave-di-prova')
        && $request->hasHeader('Accept', 'application/json');
}

it('legge lo stato del backend', function () {
    fakeBackend(['GET health' => Http::response(contract('health.200'))]);

    expect($this->backend->health())->toBeTrue();
});

it('dice che il backend non è in salute se lo stato non è ok', function () {
    fakeBackend(['GET health' => Http::response(['status' => 'starting'])]);

    expect($this->backend->health())->toBeFalse();
});

it('carica un documento in multipart con il nome originale del file', function () {
    fakeBackend(['POST agents/demo-valtesa/documents' => Http::response(contract('documents.upload.201'), 201)]);

    $file = UploadedFile::fake()->createWithContent('Regolamento Ferie (2026).pdf', '%PDF-1.7 contenuto finto');

    $document = $this->backend->uploadDocument('demo-valtesa', $file);

    expect($document->documentId)->toBe('regolamento-ferie-permessi')
        ->and($document->filename)->toBe('regolamento-ferie-permessi.pdf')
        ->and($document->chunks)->toBe(14)
        ->and($document->sizeBytes)->toBe(48213)
        ->and($document->uploadedAt)->toEqual(CarbonImmutable::parse('2026-10-31T10:12:04Z'))
        ->and($document->replaced)->toBeFalse();

    Http::assertSent(fn (Request $request) => sentWithKey($request)
        && $request->method() === 'POST'
        && $request->isMultipart()
        && $request->hasFile('file', filename: 'Regolamento Ferie (2026).pdf')
        && str_contains($request->body(), '%PDF-1.7 contenuto finto'));
});

it('elenca i documenti di un agente', function () {
    fakeBackend(['GET agents/demo-valtesa/documents' => Http::response(contract('documents.list.200'))]);

    $documents = $this->backend->documents('demo-valtesa');

    expect($documents)->toHaveCount(2)
        ->and($documents[0]->documentId)->toBe('circolari-personale-2026')
        ->and($documents[0]->chunks)->toBe(9)
        ->and($documents[1]->filename)->toBe('regolamento-ferie-permessi.pdf');

    Http::assertSent(fn (Request $request) => sentWithKey($request) && $request->method() === 'GET');
});

it('carica una tabella in multipart con il nome originale del file', function () {
    fakeBackend(['POST agents/demo-valtesa/tables' => Http::response(contract('tables.upload.201'), 201)]);

    $file = UploadedFile::fake()->createWithContent('Note spese.csv', "id,importo\n1,12.5\n");

    $table = $this->backend->uploadTable('demo-valtesa', $file);

    expect($table->name)->toBe('note_spese')
        ->and($table->rows)->toBe(180)
        ->and($table->columns)->toHaveCount(7)
        ->and($table->columns[5]->name)->toBe('importo')
        ->and($table->columns[5]->type)->toBe('REAL');

    Http::assertSent(fn (Request $request) => sentWithKey($request)
        && $request->isMultipart()
        && $request->hasFile('file', filename: 'Note spese.csv'));
});

it('elenca le tabelle con le colonne e i tipi', function () {
    fakeBackend(['GET agents/demo-valtesa/tables' => Http::response(contract('tables.list.200'))]);

    $tables = $this->backend->tables('demo-valtesa');

    expect($tables)->toHaveCount(1)
        ->and($tables[0]->name)->toBe('note_spese')
        ->and($tables[0]->filename)->toBe('note_spese.csv')
        ->and(array_map(fn ($column) => "{$column->name} {$column->type}", $tables[0]->columns))
        ->toBe(['id INTEGER', 'dipendente_id INTEGER', 'importo REAL', 'stato TEXT']);
});

it('esegue una SELECT sulle tabelle dell\'agente', function () {
    fakeBackend(['POST agents/demo-valtesa/sql' => Http::response(contract('sql.200'))]);

    $result = $this->backend->sql('demo-valtesa', 'SELECT stato, COUNT(*) FROM note_spese GROUP BY stato');

    expect($result->columns)->toBe(['stato', 'quante', 'totale'])
        ->and($result->rows)->toBe([['approvata', 98, 4120.5], ['in attesa', 56, 2184.4]])
        ->and($result->truncated)->toBeFalse();

    Http::assertSent(fn (Request $request) => sentWithKey($request)
        && $request->data() === ['sql' => 'SELECT stato, COUNT(*) FROM note_spese GROUP BY stato']);
});

it('manda un messaggio in chat con istruzioni e nome dell\'agente', function () {
    fakeBackend(['POST agents/demo-valtesa/chat' => Http::response(contract('chat.200'))]);

    $reply = $this->backend->chat(
        agentId: 'demo-valtesa',
        sessionId: '01jbx3k6v2c8m0q4w5t7y9z1ab',
        message: 'Qual è il massimale per una cena a Milano?',
        instructions: "Sei l'assistente dell'ufficio del personale.",
        agentName: 'Assistente Valtesa',
    );

    expect($reply->status)->toBe('ok')
        ->and($reply->reachedMaxTurns())->toBeFalse()
        ->and($reply->route)->toBe('documenti')
        ->and($reply->answer)->toContain('[circolari-personale-2026-004]')
        ->and($reply->tools[0]->name)->toBe('cerca_documenti')
        ->and($reply->tools[0]->arguments)->toBe(['domanda' => 'massimale cena trasferta Milano'])
        ->and($reply->sources[0]->id)->toBe('circolari-personale-2026-004')
        ->and($reply->sources[0]->documentId)->toBe('circolari-personale-2026');

    Http::assertSent(fn (Request $request) => sentWithKey($request) && $request->data() === [
        'session_id' => '01jbx3k6v2c8m0q4w5t7y9z1ab',
        'message' => 'Qual è il massimale per una cena a Milano?',
        'instructions' => "Sei l'assistente dell'ufficio del personale.",
        'agent_name' => 'Assistente Valtesa',
    ]);
});

it('riconosce la risposta di un agente fermato dopo troppi passaggi', function () {
    fakeBackend(['POST agents/demo-valtesa/chat' => Http::response(contract('chat.max-turns.200'))]);

    $reply = $this->backend->chat('demo-valtesa', 'sessione-1', 'Quante note spese sono in attesa?', '', 'Assistente Valtesa');

    expect($reply->reachedMaxTurns())->toBeTrue()
        ->and($reply->route)->toBe('dati')
        ->and($reply->tools[0]->arguments)->toHaveKey('sql')
        ->and($reply->sources)->toBe([]);
});

it('copia documenti e tabelle di un agente demo in un agente nuovo', function () {
    fakeBackend(['POST agents/01jbx3k6v2c8m0q4w5t7y9z1ab/clone' => Http::response(contract('agents.clone.201'), 201)]);

    $clone = $this->backend->cloneAgent('01jbx3k6v2c8m0q4w5t7y9z1ab', 'demo-valtesa');

    expect($clone->agentId)->toBe('01jbx3k6v2c8m0q4w5t7y9z1ab')
        ->and($clone->from)->toBe('demo-valtesa')
        ->and($clone->documents)->toBe(6)
        ->and($clone->tables)->toBe(3);

    Http::assertSent(fn (Request $request) => sentWithKey($request)
        && $request->method() === 'POST'
        && $request->data() === ['from' => 'demo-valtesa']);
});

it('una copia rifiutata arriva come BackendException con il detail', function () {
    fakeBackend(['POST agents/01jbx3k6v2c8m0q4w5t7y9z1ab/clone' => Http::response(['detail' => "L'agente di destinazione ha già dei dati."], 409)]);

    expect(fn () => $this->backend->cloneAgent('01jbx3k6v2c8m0q4w5t7y9z1ab', 'demo-valtesa'))
        ->toThrow(BackendException::class, "L'agente di destinazione ha già dei dati.");
});

it('cancella con DELETE', function (string $method, array $arguments, string $path) {
    fakeBackend(["DELETE {$path}" => Http::response(status: 204)]);

    $this->backend->{$method}(...$arguments);

    Http::assertSent(fn (Request $request) => sentWithKey($request)
        && $request->method() === 'DELETE'
        && $request->url() === "http://backend.test/{$path}");
})->with([
    'un documento' => ['deleteDocument', ['demo-valtesa', 'regolamento-ferie-permessi'], 'agents/demo-valtesa/documents/regolamento-ferie-permessi'],
    'una tabella' => ['deleteTable', ['demo-valtesa', 'note_spese'], 'agents/demo-valtesa/tables/note_spese'],
    'una sessione' => ['deleteSession', ['demo-valtesa', '01jbx3k6v2c8m0q4w5t7y9z1ab'], 'agents/demo-valtesa/sessions/01jbx3k6v2c8m0q4w5t7y9z1ab'],
    'un agente' => ['deleteAgent', ['demo-valtesa'], 'agents/demo-valtesa'],
]);

it('codifica i segmenti del percorso', function () {
    fakeBackend(['DELETE agents/demo-valtesa/tables/note%20spese%2F..' => Http::response(status: 204)]);

    $this->backend->deleteTable('demo-valtesa', 'note spese/..');

    Http::assertSentCount(1);
});

it('mostra all\'utente il detail del backend', function (int $status) {
    fakeBackend(['GET agents/demo-valtesa/documents' => Http::response(contract('error'), $status)]);

    expect(fn () => $this->backend->documents('demo-valtesa'))->toThrow(
        BackendException::class,
        "Il file non è un PDF valido: il contenuto non corrisponde all'estensione.",
    );
})->with([404, 409, 413, 415, 502]);

it('usa un messaggio generico se detail non è una stringa', function () {
    fakeBackend(['POST agents/demo-valtesa/sql' => Http::response([
        'detail' => [['loc' => ['body', 'sql'], 'msg' => 'Field required', 'type' => 'missing']],
    ], 422)]);

    expect(fn () => $this->backend->sql('demo-valtesa', ''))->toThrow(
        BackendException::class,
        'Il backend ha risposto con un errore inatteso (codice 422).',
    );
});

it('usa un messaggio generico se il backend non risponde in JSON', function () {
    Log::spy();
    fakeBackend(['GET agents/demo-valtesa/tables' => Http::response('Internal Server Error', 500)]);

    try {
        $this->backend->tables('demo-valtesa');
        $this->fail('Doveva lanciare BackendException.');
    } catch (BackendException $e) {
        expect($e->getMessage())->toBe('Il backend ha risposto con un errore inatteso (codice 500).')
            ->and($e->status)->toBe(500);
    }

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'Errore del backend'));
});

it('non rivela la chiave quando il backend la rifiuta e segnala la configurazione nel log', function () {
    Log::spy();
    fakeBackend(['GET agents/demo-valtesa/documents' => Http::response(['detail' => 'Chiave interna mancante o sbagliata.'], 401)]);

    try {
        $this->backend->documents('demo-valtesa');
        $this->fail('Doveva lanciare BackendException.');
    } catch (BackendException $e) {
        expect($e->getMessage())->toContain('problema di configurazione')
            ->not->toContain('chiave-di-prova')
            ->and($e->status)->toBe(401);
    }

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => str_contains($message, 'STAPPLY_BACKEND_KEY')
        && ! str_contains($message, 'chiave-di-prova'));
});

it('dice che il backend non è raggiungibile se la connessione fallisce', function (string $method, array $arguments) {
    Http::fake(Http::failedConnection('cURL error 7: Failed to connect to backend.test port 80'));

    expect(fn () => $this->backend->{$method}(...$arguments))->toThrow(
        BackendException::class,
        'Il backend non è raggiungibile o non ha risposto in tempo: riprova tra poco.',
    );
})->with([
    'health' => ['health', []],
    'documents' => ['documents', ['demo-valtesa']],
    'uploadDocument' => ['uploadDocument', ['demo-valtesa', UploadedFile::fake()->createWithContent('faq.md', '# FAQ')]],
    'chat' => ['chat', ['demo-valtesa', 'sessione-1', 'Ciao', '', 'Assistente Valtesa']],
    'deleteAgent' => ['deleteAgent', ['demo-valtesa']],
]);

it('tratta un timeout come un backend non raggiungibile', function () {
    Http::fake(Http::failedConnection('cURL error 28: Operation timed out after 120001 milliseconds'));

    expect(fn () => $this->backend->chat('demo-valtesa', 'sessione-1', 'Ciao', '', 'Assistente Valtesa'))
        ->toThrow(BackendException::class, 'Il backend non è raggiungibile');
});
