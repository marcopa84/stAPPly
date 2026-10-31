<?php

use App\Livewire\Agents\Documents;
use App\Livewire\Agents\Show;
use App\Livewire\Agents\Tables;
use App\Models\Agent;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;

/**
 * I file nella cartella temporanea degli upload di Livewire (nei test, un disco finto).
 *
 * @return list<string>
 */
function temporaryUploads(): array
{
    return FileUploadConfiguration::storage()->allFiles(FileUploadConfiguration::directory());
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->for($this->user)->create(['backend_id' => 'agente-di-prova']);
    $this->actingAs($this->user);
});

test('la pagina mostra i documenti con chunk, dimensione e data', function () {
    fakeBackend([
        'GET agents/agente-di-prova/documents' => Http::response(contract('documents.list.200')),
        'GET agents/agente-di-prova/tables' => Http::response(['tables' => []]),
    ]);

    $this->get(route('agents.show', $this->agent))
        ->assertOk()
        ->assertSee('regolamento-ferie-permessi.pdf')
        ->assertSee('47,1 KB')
        ->assertSee('2 di 20')
        ->assertSee('Carica un documento');
});

test('la sezione tabelle mostra righe, colonne e tipi', function () {
    fakeBackend(['GET agents/agente-di-prova/tables' => Http::response(contract('tables.list.200'))]);

    $this->get(route('agents.show', ['agent' => $this->agent, 'section' => 'tabelle']))
        ->assertOk()
        ->assertSee('note_spese.csv')
        ->assertSee('180')
        ->assertSee('dipendente_id')
        ->assertSee('INTEGER')
        ->assertSee('1 di 10');
});

test('una sezione sconosciuta riporta ai documenti', function () {
    fakeBackend([
        'GET agents/agente-di-prova/documents' => Http::response(['documents' => []]),
        'GET agents/agente-di-prova/tables' => Http::response(['tables' => []]),
    ]);

    Livewire::withQueryParams(['section' => 'inesistente'])
        ->test(Show::class, ['agent' => $this->agent])
        ->assertSet('section', 'documenti');
});

test('con il backend spento la pagina si apre e dice perché mancano i documenti', function () {
    Http::fake(Http::failedConnection());

    $this->get(route('agents.show', $this->agent))
        ->assertOk()
        ->assertSee('Non riesco a leggere i documenti')
        ->assertSee('Il backend non è raggiungibile');
});

test('il documento caricato va al backend in multipart con il nome originale', function () {
    fakeBackend([
        'GET agents/agente-di-prova/documents' => Http::response(contract('documents.list.200')),
        'POST agents/agente-di-prova/documents' => Http::response(contract('documents.upload.201'), 201),
    ]);

    Livewire::test(Documents::class, ['agent' => $this->agent])
        ->set('file', UploadedFile::fake()->createWithContent('Regolamento Ferie (2026).pdf', '%PDF-1.7 finto'))
        ->call('upload')
        ->assertHasNoErrors()
        ->assertSet('file', null)
        ->assertSet('fileInputKey', 1)
        ->assertDispatched('toast-show');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'http://backend.test/agents/agente-di-prova/documents'
        && $request->hasHeader('X-Internal-Key', 'chiave-di-prova')
        && $request->hasFile('file', filename: 'Regolamento Ferie (2026).pdf')
        && str_contains($request->body(), '%PDF-1.7 finto'));
});

test('la tabella caricata va al backend in multipart con il nome originale', function () {
    fakeBackend([
        'GET agents/agente-di-prova/tables' => Http::response(contract('tables.list.200')),
        'POST agents/agente-di-prova/tables' => Http::response(contract('tables.upload.201'), 201),
    ]);

    Livewire::test(Tables::class, ['agent' => $this->agent])
        ->set('file', UploadedFile::fake()->createWithContent('Note spese.csv', "id,importo\n1,12.5\n"))
        ->call('upload')
        ->assertHasNoErrors()
        ->assertSet('file', null);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'http://backend.test/agents/agente-di-prova/tables'
        && $request->hasFile('file', filename: 'Note spese.csv'));
});

test('i file non ammessi si fermano appena scelti, prima del backend, e non restano sul server', function (string $component, string $list, UploadedFile $file, string $message) {
    fakeBackend(["GET agents/agente-di-prova/{$list}" => Http::response([$list => []])]);

    Livewire::test($component, ['agent' => $this->agent])
        ->set('file', $file)
        ->assertHasErrors('file')
        ->assertSee($message)
        ->assertSet('file', null)
        ->assertSet('fileInputKey', 1);

    expect(temporaryUploads())->toBe([]);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
})->with([
    'estensione da documento' => [Documents::class, 'documents', UploadedFile::fake()->create('foto.png', 10), 'carica un file .txt, .md, .pdf, .docx, .html o .htm'],
    'estensione da tabella' => [Tables::class, 'tables', UploadedFile::fake()->create('dati.json', 10), 'carica un file .csv o .xlsx'],
    'oltre 10 MB' => [Documents::class, 'documents', UploadedFile::fake()->create('manuale.pdf', 10 * 1024 + 1), 'supera il limite di 10 MB'],
]);

test('dopo l\'invio la copia temporanea del file si cancella, anche se il backend lo rifiuta', function (PromiseInterface $response) {
    fakeBackend([
        'GET agents/agente-di-prova/documents' => Http::response(['documents' => []]),
        'POST agents/agente-di-prova/documents' => $response,
    ]);

    $section = Livewire::test(Documents::class, ['agent' => $this->agent])
        ->set('file', UploadedFile::fake()->createWithContent('riservato.pdf', '%PDF-1.7 finto'));

    // Il file e, accanto, i suoi metadati con il nome originale.
    expect(temporaryUploads())->toHaveCount(2);

    $section->call('upload')
        ->assertSet('file', null)
        ->assertSet('fileInputKey', 1);

    expect(temporaryUploads())->toBe([]);
})->with([
    'caricato' => fn () => Http::response(contract('documents.upload.201'), 201),
    'rifiutato' => fn () => Http::response(contract('error'), 415),
]);

test("l'errore del backend sull'upload compare sotto il campo", function () {
    fakeBackend([
        'GET agents/agente-di-prova/documents' => Http::response(['documents' => []]),
        'POST agents/agente-di-prova/documents' => Http::response(contract('error'), 415),
    ]);

    Livewire::test(Documents::class, ['agent' => $this->agent])
        ->set('file', UploadedFile::fake()->createWithContent('finto.pdf', 'non sono un pdf'))
        ->call('upload')
        ->assertHasErrors('file')
        ->assertSee("Il file non è un PDF valido: il contenuto non corrisponde all'estensione.");
});

test('un documento si elimina dopo la conferma nel modal', function () {
    fakeBackend([
        'GET agents/agente-di-prova/documents' => Http::response(contract('documents.list.200')),
        'DELETE agents/agente-di-prova/documents/regolamento-ferie-permessi' => Http::response(status: 204),
    ]);

    Livewire::test(Documents::class, ['agent' => $this->agent])
        ->call('confirmDelete', 'regolamento-ferie-permessi', 'regolamento-ferie-permessi.pdf')
        ->assertSet('showDeleteModal', true)
        ->assertSee('Eliminare il documento?')
        ->call('delete')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingId', null)
        ->assertDispatched('toast-show');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
});

test('una tabella si elimina dopo la conferma nel modal', function () {
    fakeBackend([
        'GET agents/agente-di-prova/tables' => Http::response(contract('tables.list.200')),
        'DELETE agents/agente-di-prova/tables/note_spese' => Http::response(status: 204),
    ]);

    Livewire::test(Tables::class, ['agent' => $this->agent])
        ->call('confirmDelete', 'note_spese', 'note_spese')
        ->call('delete')
        ->assertSet('showDeleteModal', false);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
});

test('un id che non ha la forma di docs/api.md non arriva al backend', function (string $component, string $id) {
    fakeBackend([
        'GET agents/agente-di-prova/documents' => Http::response(['documents' => []]),
        'GET agents/agente-di-prova/tables' => Http::response(['tables' => []]),
    ]);

    Livewire::test($component, ['agent' => $this->agent])
        ->call('confirmDelete', $id, 'trappola')
        ->assertNotFound();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
})->with([
    'documento ".."' => [Documents::class, '..'],
    'documento con estensione' => [Documents::class, 'regolamento.pdf'],
    'documento con a capo' => [Documents::class, "regolamento\n"],
    'tabella ".."' => [Tables::class, '..'],
    'tabella con un percorso' => [Tables::class, 'note_spese/../..'],
]);

test('se il backend non elimina il documento il modal resta aperto con il motivo', function () {
    fakeBackend([
        'GET agents/agente-di-prova/documents' => Http::response(contract('documents.list.200')),
        'DELETE agents/agente-di-prova/documents/regolamento-ferie-permessi' => Http::response(['detail' => 'Documento non trovato.'], 404),
    ]);

    Livewire::test(Documents::class, ['agent' => $this->agent])
        ->call('confirmDelete', 'regolamento-ferie-permessi', 'regolamento-ferie-permessi.pdf')
        ->call('delete')
        ->assertSet('showDeleteModal', true)
        ->assertSet('deleteError', 'Documento non trovato.')
        ->assertSee('Documento non trovato.');
});
