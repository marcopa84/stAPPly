<?php

use App\Livewire\Agents\Documents;
use App\Livewire\Agents\Edit;
use App\Livewire\Agents\Show;
use App\Livewire\Agents\Tables;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->agent = Agent::factory()->for($this->owner)->create();
    $this->stranger = User::factory()->create();
});

test("un altro utente non vede né modifica l'agente", function (string $route) {
    $this->actingAs($this->stranger)
        ->get(route($route, $this->agent))
        ->assertForbidden();
})->with(['agents.show', 'agents.edit']);

test("un altro utente non usa i componenti dell'agente", function (string $component) {
    Livewire::actingAs($this->stranger)
        ->test($component, ['agent' => $this->agent])
        ->assertForbidden();

    Http::assertNothingSent();
})->with([Show::class, Edit::class, Documents::class, Tables::class]);

test("l'agente di modifica, documenti e tabelle non si cambia dal browser", function (string $component) {
    fakeBackend([
        'GET agents/'.$this->agent->backend_id.'/documents' => Http::response(['documents' => []]),
        'GET agents/'.$this->agent->backend_id.'/tables' => Http::response(['tables' => []]),
    ]);

    expect(fn () => Livewire::actingAs($this->owner)
        ->test($component, ['agent' => $this->agent])
        ->set('agent', Agent::factory()->create()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([Edit::class, Documents::class, Tables::class]);

test('un agente inesistente dà 404', function () {
    $this->actingAs($this->owner)
        ->get(route('agents.show', ['agent' => 'agente-che-non-esiste']))
        ->assertNotFound();
});

describe('agenti demo', function () {
    beforeEach(function () {
        $this->demo = Agent::factory()->demo()->for($this->owner)->create(['backend_id' => 'demo-valtesa']);
        $this->actingAs($this->owner);
    });

    test('la pagina si apre con il backend_id demo nell\'URL', function () {
        fakeBackend([
            'GET agents/demo-valtesa/documents' => Http::response(contract('documents.list.200')),
            'GET agents/demo-valtesa/tables' => Http::response(contract('tables.list.200')),
        ]);

        $this->get('/agents/demo-valtesa')
            ->assertOk()
            ->assertSee('circolari-personale-2026.html')
            ->assertSee('Agente demo')
            ->assertDontSee('Carica un documento');
    });

    test('non si eliminano e il backend non viene chiamato', function () {
        Livewire::test(Edit::class, ['agent' => $this->demo])
            ->assertSee('Gli agenti demo non si eliminano')
            ->call('delete')
            ->assertForbidden();

        $this->assertModelExists($this->demo);
        Http::assertNothingSent();
    });

    test('non accettano upload', function (string $component, string $list, string $filename) {
        fakeBackend(["GET agents/demo-valtesa/{$list}" => Http::response([$list => []])]);

        Livewire::test($component, ['agent' => $this->demo])
            ->set('file', UploadedFile::fake()->createWithContent($filename, 'contenuto'))
            ->call('upload')
            ->assertForbidden();

        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    })->with([
        'documenti' => [Documents::class, 'documents', 'faq.md'],
        'tabelle' => [Tables::class, 'tables', 'prestiti.csv'],
    ]);

    test('non si eliminano i loro documenti', function () {
        fakeBackend(['GET agents/demo-valtesa/documents' => Http::response(contract('documents.list.200'))]);

        Livewire::test(Documents::class, ['agent' => $this->demo])
            ->call('confirmDelete', 'circolari-personale-2026', 'circolari-personale-2026.html')
            ->assertForbidden();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    });
});
