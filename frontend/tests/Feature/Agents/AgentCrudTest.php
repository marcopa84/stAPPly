<?php

use App\Livewire\Agents\Create;
use App\Livewire\Agents\Edit;
use App\Livewire\Agents\Index;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** La regex di agent_id in docs/api.md. */
const AGENT_ID_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,48}[a-z0-9])?$/';

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('gli ospiti vanno al login', function () {
    auth()->logout();

    $this->get(route('agents.index'))->assertRedirect(route('login'));
});

test("l'elenco mostra solo gli agenti dell'utente", function () {
    Agent::factory()->for($this->user)->create(['name' => 'Il mio agente']);
    Agent::factory()->create(['name' => 'Agente di un altro']);

    $this->get(route('agents.index'))
        ->assertOk()
        ->assertSee('Il mio agente')
        ->assertDontSee('Agente di un altro');
});

test("l'elenco vuoto invita a creare il primo agente", function () {
    Livewire::test(Index::class)->assertSee('Non hai ancora agenti');
});

test('si crea un agente con un backend_id valido per il backend', function () {
    Livewire::test(Create::class)
        ->set('form.name', '  Assistente acquisti  ')
        ->set('form.description', '')
        ->set('form.instructions', "Sei l'assistente dell'ufficio acquisti.")
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('agents.show', Agent::sole()));

    $agent = Agent::sole();

    expect($agent->user_id)->toBe($this->user->id)
        ->and($agent->name)->toBe('Assistente acquisti')
        ->and($agent->description)->toBeNull()
        ->and($agent->is_demo)->toBeFalse()
        ->and($agent->backend_id)->toHaveLength(26)->toMatch(AGENT_ID_PATTERN);
});

test("ogni agente ha un backend_id diverso e l'URL non usa l'id numerico", function () {
    [$first, $second] = Agent::factory()->count(2)->for($this->user)->create();

    expect($first->backend_id)->not->toBe($second->backend_id)
        ->and(route('agents.show', $first))->toEndWith("/agents/{$first->backend_id}");
});

test('la creazione valida i campi con i limiti della chat', function (string $field, string $value, string $rule) {
    Livewire::test(Create::class)
        ->set('form.name', 'Agente')
        ->set('form.instructions', 'Istruzioni.')
        ->set("form.{$field}", $value)
        ->call('save')
        ->assertHasErrors(["form.{$field}" => $rule]);

    expect(Agent::count())->toBe(0);
})->with([
    'nome mancante' => ['name', '', 'required'],
    'nome troppo lungo' => ['name', str_repeat('a', 101), 'max'],
    'descrizione troppo lunga' => ['description', str_repeat('a', 256), 'max'],
    'istruzioni mancanti' => ['instructions', '   ', 'required'],
    'istruzioni troppo lunghe' => ['instructions', str_repeat('a', 8001), 'max'],
]);

test('la pagina di modifica mostra le istruzioni e il testo sulle regole fisse', function () {
    $agent = Agent::factory()->for($this->user)->create(['instructions' => 'Rispondi sulle ferie.']);

    $this->get(route('agents.edit', $agent))
        ->assertOk()
        ->assertSee('Rispondi sulle ferie.')
        ->assertSee('stAPPly aggiunge sempre le sue regole fisse');
});

test('si modificano nome, descrizione e istruzioni', function () {
    $agent = Agent::factory()->for($this->user)->create();

    Livewire::test(Edit::class, ['agent' => $agent])
        ->assertSet('form.name', $agent->name)
        ->set('form.name', 'Nuovo nome')
        ->set('form.description', 'Nuova descrizione')
        ->set('form.instructions', 'Nuove istruzioni.')
        ->call('save')
        ->assertHasNoErrors();

    expect($agent->refresh())
        ->name->toBe('Nuovo nome')
        ->description->toBe('Nuova descrizione')
        ->instructions->toBe('Nuove istruzioni.');
});

test('le istruzioni di un agente demo si possono correggere', function () {
    $agent = Agent::factory()->demo()->for($this->user)->create();

    Livewire::test(Edit::class, ['agent' => $agent])
        ->set('form.instructions', 'Istruzioni corrette dopo la griglia.')
        ->call('save')
        ->assertHasNoErrors();

    expect($agent->refresh()->instructions)->toBe('Istruzioni corrette dopo la griglia.');
});

test('eliminare un agente cancella prima i suoi dati nel backend', function () {
    $agent = Agent::factory()->for($this->user)->create();
    fakeBackend(["DELETE agents/{$agent->backend_id}" => Http::response(status: 204)]);

    Livewire::test(Edit::class, ['agent' => $agent])
        ->call('delete')
        ->assertRedirect(route('agents.index'));

    $this->assertModelMissing($agent);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === "http://backend.test/agents/{$agent->backend_id}");
});

test("se il backend non elimina i dati l'agente resta e l'utente vede il motivo", function ($response, string $message) {
    $agent = Agent::factory()->for($this->user)->create();
    fakeBackend(["DELETE agents/{$agent->backend_id}" => $response]);

    Livewire::test(Edit::class, ['agent' => $agent])
        ->call('delete')
        ->assertNoRedirect()
        ->assertSet('deleteError', $message)
        ->assertSee($message);

    $this->assertModelExists($agent);
})->with([
    'backend spento' => [fn () => Http::failedConnection(), 'Il backend non è raggiungibile o non ha risposto in tempo: riprova tra poco.'],
    'errore del backend' => [fn () => Http::response(['detail' => 'Errore nel cancellare la collection.'], 500), 'Errore nel cancellare la collection.'],
]);
