<?php

use App\Livewire\Agents\Show;
use App\Livewire\Agents\SqlConsole;
use App\Models\Agent;
use App\Models\User;
use App\Services\Backend\Data\SqlResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->for($this->user)->create(['backend_id' => 'agente-di-prova']);
    $this->actingAs($this->user);
});

test('la query va al backend e il risultato compare in tabella', function () {
    fakeBackend([
        'GET agents/agente-di-prova/tables' => Http::response(contract('tables.list.200')),
        'POST agents/agente-di-prova/sql' => Http::response(contract('sql.200')),
    ]);

    Livewire::test(SqlConsole::class, ['agent' => $this->agent])
        ->set('sql', "  SELECT stato, COUNT(*) AS quante, SUM(importo) AS totale FROM note_spese GROUP BY stato\n")
        ->call('run')
        ->assertHasNoErrors()
        ->assertSee('quante')
        ->assertSee('approvata')
        ->assertSee('4120.5')
        ->assertSee('2 righe')
        ->assertDontSee('Risultato tagliato');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'http://backend.test/agents/agente-di-prova/sql'
        && $request->hasHeader('X-Internal-Key', 'chiave-di-prova')
        && $request->data() === ['sql' => 'SELECT stato, COUNT(*) AS quante, SUM(importo) AS totale FROM note_spese GROUP BY stato']);
});

test('accanto alla console c\'è lo schema delle tabelle', function () {
    fakeBackend(['GET agents/agente-di-prova/tables' => Http::response(contract('tables.list.200'))]);

    Livewire::test(SqlConsole::class, ['agent' => $this->agent])
        ->assertSee('Schema')
        ->assertSee('note_spese')
        ->assertSee('dipendente_id')
        ->assertSee('REAL')
        ->assertSee('180 righe, da note_spese.csv')
        // Un "Prova" per tabella: lo screen reader deve sapere quale.
        ->assertSeeHtml('aria-label="Prova la tabella note_spese"');
});

test('un risultato tagliato dal backend lo dice', function () {
    fakeBackend([
        'GET agents/agente-di-prova/tables' => Http::response(contract('tables.list.200')),
        'POST agents/agente-di-prova/sql' => Http::response([...contract('sql.200'), 'truncated' => true]),
    ]);

    Livewire::test(SqlConsole::class, ['agent' => $this->agent])
        ->set('sql', 'SELECT * FROM note_spese')
        ->call('run')
        ->assertSee('Risultato tagliato');
});

test('una query rifiutata dal backend mostra il motivo', function () {
    fakeBackend([
        'GET agents/agente-di-prova/tables' => Http::response(contract('tables.list.200')),
        'POST agents/agente-di-prova/sql' => Http::response(['detail' => 'Sono ammesse solo query SELECT o WITH.'], 422),
    ]);

    Livewire::test(SqlConsole::class, ['agent' => $this->agent])
        ->set('sql', 'DELETE FROM note_spese')
        ->call('run')
        ->assertSee('La query non è andata')
        ->assertSee('Sono ammesse solo query SELECT o WITH.')
        ->assertSet('sql', 'DELETE FROM note_spese');
});

test('una query vuota non parte', function () {
    fakeBackend(['GET agents/agente-di-prova/tables' => Http::response(contract('tables.list.200'))]);

    Livewire::test(SqlConsole::class, ['agent' => $this->agent])
        ->set('sql', '   ')
        ->call('run')
        ->assertHasErrors(['sql' => 'required']);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('senza tabelle la console non c\'è, né come sezione né come componente', function () {
    fakeBackend([
        'GET agents/agente-di-prova/documents' => Http::response(['documents' => []]),
        'GET agents/agente-di-prova/tables' => Http::response(['tables' => []]),
    ]);

    $this->get(route('agents.show', $this->agent))
        ->assertOk()
        ->assertDontSee('Console SQL');

    Livewire::withQueryParams(['section' => 'sql'])
        ->test(Show::class, ['agent' => $this->agent])
        ->assertSet('section', 'documenti');

    Livewire::test(SqlConsole::class, ['agent' => $this->agent])
        ->assertSee('Nessuna tabella da interrogare')
        ->assertDontSee('Esegui');
});

test('con le tabelle la console è una sezione della pagina dell\'agente', function () {
    fakeBackend(['GET agents/agente-di-prova/tables' => Http::response(contract('tables.list.200'))]);

    $this->get(route('agents.show', ['agent' => $this->agent, 'section' => 'sql']))
        ->assertOk()
        ->assertSee('Console SQL')
        ->assertSee('Esegui');
});

test('con il backend spento la sezione si nasconde e la console dice perché', function () {
    Http::fake(Http::failedConnection());

    $this->get(route('agents.show', $this->agent))
        ->assertOk()
        ->assertDontSee('Console SQL');

    Livewire::test(SqlConsole::class, ['agent' => $this->agent])
        ->assertSee('Non riesco a leggere le tabelle')
        ->assertSee('Il backend non è raggiungibile');
});

test('la console funziona anche con gli agenti demo, che sono in sola lettura', function () {
    $demo = Agent::factory()->demo()->for($this->user)->create(['backend_id' => 'demo-valtesa']);
    fakeBackend([
        'GET agents/demo-valtesa/tables' => Http::response(contract('tables.list.200')),
        'POST agents/demo-valtesa/sql' => Http::response(contract('sql.200')),
    ]);

    Livewire::test(SqlConsole::class, ['agent' => $demo])
        ->set('sql', 'SELECT stato, COUNT(*) FROM note_spese GROUP BY stato')
        ->call('run')
        ->assertSee('in attesa');
});

test('le colonne di soli numeri, NULL a parte, si allineano a destra', function () {
    $result = SqlResult::fromArray([
        'columns' => ['stato', 'quante', 'totale', 'note'],
        'rows' => [['approvata', 98, 4120.5, null], ['in attesa', null, 2184.4, null]],
        'truncated' => false,
    ]);

    expect(array_map($result->isNumeric(...), [0, 1, 2, 3]))->toBe([false, true, true, false]);
});
