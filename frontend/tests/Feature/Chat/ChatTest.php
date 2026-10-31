<?php

use App\Enums\MessageRole;
use App\Livewire\Agents\Chat;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** La regex di session_id in docs/api.md. */
const SESSION_ID_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->for($this->user)->create([
        'backend_id' => 'agente-di-prova',
        'name' => 'Assistente Valtesa',
        'instructions' => "Sei l'assistente dell'ufficio del personale di Valtesa.",
    ]);
    $this->conversation = Conversation::factory()->for($this->agent)->create(['title' => 'Trasferte']);
    $this->actingAs($this->user);
});

/**
 * Il componente della chat sulla conversazione aperta nel beforeEach.
 */
function openChat(): Testable
{
    return Livewire::test(Chat::class, ['agent' => test()->agent, 'conversation' => test()->conversation]);
}

test('la pagina elenca le chat con l\'agente e apre quella nell\'URL', function () {
    Conversation::factory()->for($this->agent)->create(['title' => 'Ferie 2026']);

    $this->get(route('agents.chat', ['agent' => $this->agent, 'conversation' => $this->conversation]))
        ->assertOk()
        ->assertSee('Chat con Assistente Valtesa')
        ->assertSee('Ferie 2026')
        ->assertSee('Trasferte')
        ->assertSee('Fai la prima domanda a Assistente Valtesa.');

    Http::assertNothingSent();
});

test('senza conversazione nell\'URL la pagina invita a sceglierne una', function () {
    $this->get(route('agents.chat', $this->agent))
        ->assertOk()
        ->assertSee('Scegli una chat o iniziane una nuova')
        ->assertDontSee('Invia');
});

test("la pagina dell'agente porta alla chat", function () {
    fakeBackend([
        'GET agents/agente-di-prova/documents' => Http::response(['documents' => []]),
        'GET agents/agente-di-prova/tables' => Http::response(['tables' => []]),
    ]);

    $this->get(route('agents.show', $this->agent))
        ->assertOk()
        ->assertSee(route('agents.chat', $this->agent));
});

test('il messaggio va al backend con sessione, istruzioni e nome, e la risposta si salva con la traccia', function () {
    fakeBackend(['POST agents/agente-di-prova/chat' => Http::response(contract('chat.200'))]);

    openChat()
        ->set('message', "  Qual è il massimale per una cena in trasferta a Milano?\n")
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('message', '')
        ->assertSet('awaitingReply', true)
        ->assertSee('Qual è il massimale per una cena in trasferta a Milano?')
        ->call('reply')
        ->assertSet('awaitingReply', false)
        ->assertSet('error', null)
        ->assertSee('40 € dal 1° luglio 2026');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'http://backend.test/agents/agente-di-prova/chat'
        && $request->hasHeader('X-Internal-Key', 'chiave-di-prova')
        && $request->data() === [
            'session_id' => $this->conversation->session_id,
            'message' => 'Qual è il massimale per una cena in trasferta a Milano?',
            'instructions' => "Sei l'assistente dell'ufficio del personale di Valtesa.",
            'agent_name' => 'Assistente Valtesa',
        ]);

    [$question, $answer] = $this->conversation->messages()->oldest('id')->get();

    expect($question->role)->toBe(MessageRole::User)
        ->and($question->content)->toBe('Qual è il massimale per una cena in trasferta a Milano?')
        ->and($answer->role)->toBe(MessageRole::Assistant)
        ->and($answer->content)->toBe(contract('chat.200')['answer'])
        ->and($answer->status)->toBe('ok')
        ->and($answer->route)->toBe('documenti')
        ->and($answer->tools)->toBe(contract('chat.200')['tools'])
        ->and($answer->sources)->toBe(contract('chat.200')['sources']);
});

test('sotto la risposta ci sono la strada, il tool con la ricerca e il chip del passaggio citato', function () {
    Message::factory()->for($this->conversation)->create(['content' => 'Massimale cena a Milano?']);
    Message::factory()->assistant()->for($this->conversation)->create([
        'content' => contract('chat.200')['answer'],
        'route' => 'documenti',
        'tools' => contract('chat.200')['tools'],
        'sources' => contract('chat.200')['sources'],
    ]);

    openChat()
        ->assertSee('documenti')
        ->assertSee('Ha cercato nei documenti.')
        ->assertSee('cerca_documenti')
        ->assertSee('massimale cena trasferta Milano')
        ->assertSeeHtml('<button type="button" class="citation-chip" aria-label="Passaggio citato circolari-personale-2026-004, da circolari-personale-2026.html">circolari-personale-2026-004</button>')
        ->assertSee('Dal 1° luglio 2026 il massimale per la cena in trasferta sale da 35 € a 40 €.')
        ->assertDontSee("L'agente si è fermato prima di rispondere");
});

test("con max_turns la traccia avvisa che l'agente si è fermato e mostra la SQL", function () {
    fakeBackend(['POST agents/agente-di-prova/chat' => Http::response(contract('chat.max-turns.200'))]);

    openChat()
        ->set('message', 'Quante note spese sono in attesa?')
        ->call('send')
        ->call('reply')
        ->assertSee("L'agente si è fermato prima di rispondere")
        ->assertSee('Non sono riuscito a completare la risposta')
        ->assertSee('Ha interrogato le tabelle.')
        ->assertSeeHtml('<code>SELECT COUNT(*) FROM note_spese WHERE stato = &#039;in attesa&#039;</code>');

    expect($this->conversation->messages()->latest('id')->first()->reachedMaxTurns())->toBeTrue();
});

test('una domanda fuori tema mostra la strada nessuna senza tool', function () {
    Message::factory()->for($this->conversation)->create(['content' => 'Chi ha vinto lo scudetto?']);
    Message::factory()->assistant()->for($this->conversation)->create([
        'content' => 'Posso rispondere solo su ferie, trasferte e note spese di Valtesa.',
    ]);

    openChat()
        ->assertSee('nessuna')
        ->assertSee('Ha risposto senza chiamare tool.')
        ->assertSee('Nessun tool chiamato.');
});

test('lo stesso nome riapre la stessa chat, e il backend riceve la stessa sessione', function () {
    fakeBackend(['POST agents/agente-di-prova/chat' => Http::response(contract('chat.200'))]);

    Livewire::test(Chat::class, ['agent' => $this->agent])
        ->set('title', 'Ferie 2026')
        ->call('open')
        ->assertHasNoErrors();

    $conversation = Conversation::where('title', 'Ferie 2026')->sole();

    expect($conversation->user_id)->toBe($this->user->id)
        ->and($conversation->session_id)->toHaveLength(26)->toMatch(SESSION_ID_PATTERN)
        ->and($conversation->session_id)->toBe(strtolower($conversation->session_id));

    Livewire::test(Chat::class, ['agent' => $this->agent, 'conversation' => $conversation])
        ->set('message', 'Quanti giorni di ferie ho?')
        ->call('send')
        ->call('reply');

    // Chiusa e riaperta con lo stesso nome (anche con spazi in più): niente chat nuova.
    Livewire::test(Chat::class, ['agent' => $this->agent])
        ->set('title', '  Ferie 2026 ')
        ->call('open')
        ->assertRedirect(route('agents.chat', ['agent' => $this->agent, 'conversation' => $conversation]));

    Livewire::test(Chat::class, ['agent' => $this->agent, 'conversation' => $conversation])
        ->assertSee('Quanti giorni di ferie ho?')
        ->set('message', 'E quanti me ne restano?')
        ->call('send')
        ->call('reply');

    expect(Conversation::where('title', 'Ferie 2026')->count())->toBe(1);

    $sessions = Http::recorded()->map(fn (array $pair) => $pair[0]->data()['session_id'])->unique();

    expect($sessions->all())->toBe([$conversation->session_id]);
});

test('il nome riapre la chat senza badare alle maiuscole, anche accentate, e resta scritto com\'era', function () {
    $conversation = Conversation::factory()->for($this->agent)->create(['title' => 'Perché le Ferie']);

    Livewire::test(Chat::class, ['agent' => $this->agent])
        ->set('title', 'PERCHÉ LE FERIE')
        ->call('open')
        ->assertRedirect(route('agents.chat', ['agent' => $this->agent, 'conversation' => $conversation]));

    expect(Conversation::where('agent_id', $this->agent->id)->count())->toBe(2)
        ->and($conversation->fresh()->title)->toBe('Perché le Ferie');
});

test('una chat con lo stesso nome di un altro utente non si riapre', function () {
    $foreign = Conversation::factory()->for($this->agent)->create(['title' => 'Ferie 2026', 'user_id' => User::factory()]);

    Livewire::test(Chat::class, ['agent' => $this->agent])
        ->set('title', 'ferie 2026')
        ->call('open');

    $mine = Conversation::whereBelongsTo($this->user)->where('title', 'ferie 2026')->sole();

    expect($mine->id)->not->toBe($foreign->id);
});

test('un nome nuovo crea una chat nuova, con una sessione diversa', function () {
    Livewire::test(Chat::class, ['agent' => $this->agent])
        ->set('title', 'Note spese')
        ->call('open');

    $conversation = Conversation::where('title', 'Note spese')->sole();

    expect($conversation->session_id)->not->toBe($this->conversation->session_id);
});

test('nome della chat e messaggio vuoti non partono', function () {
    Livewire::test(Chat::class, ['agent' => $this->agent])
        ->set('title', '   ')
        ->call('open')
        ->assertHasErrors(['title' => 'required']);

    openChat()
        ->set('message', '   ')
        ->call('send')
        ->assertHasErrors(['message' => 'required'])
        ->assertSet('awaitingReply', false);

    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(0);
    Http::assertNothingSent();
});

test('se il backend sbaglia la domanda resta, la risposta no, e si può riprovare', function () {
    fakeBackend([
        'POST agents/agente-di-prova/chat' => Http::sequence()
            ->push(['detail' => 'Il provider del modello non risponde: riprova tra poco.'], 502)
            ->push(contract('chat.200')),
    ]);

    $chat = openChat()
        ->set('message', 'Massimale cena a Milano?')
        ->call('send')
        ->call('reply')
        ->assertSet('error', 'Il provider del modello non risponde: riprova tra poco.')
        ->assertSee('La risposta non è arrivata')
        ->assertSee('Massimale cena a Milano?')
        ->assertSee('Riprova')
        ->assertSeeHtml('data-state="confused"');

    expect($this->conversation->messages()->pluck('role')->all())->toBe([MessageRole::User]);

    $chat->call('retry')
        ->assertSet('error', null)
        ->assertSet('awaitingReply', true)
        ->call('reply')
        ->assertSee('40 € dal 1° luglio 2026')
        ->assertDontSee('La risposta non è arrivata');

    expect($this->conversation->messages()->pluck('role')->all())->toBe([MessageRole::User, MessageRole::Assistant])
        ->and($this->conversation->messages()->where('role', MessageRole::User)->count())->toBe(1);
    Http::assertSentCount(2);
});

test('con il backend spento la chat lo dice e non salva risposte', function () {
    Http::fake(Http::failedConnection());

    openChat()
        ->set('message', 'Massimale cena a Milano?')
        ->call('send')
        ->call('reply')
        ->assertSee('Il backend non è raggiungibile o non ha risposto in tempo');

    expect(Message::count())->toBe(1);
});

test('riaprendo la pagina, la domanda rimasta senza risposta si può rimandare', function () {
    Message::factory()->for($this->conversation)->create(['content' => 'Massimale cena a Milano?']);

    $this->get(route('agents.chat', ['agent' => $this->agent, 'conversation' => $this->conversation]))
        ->assertOk()
        ->assertSee('Questa domanda è rimasta senza risposta')
        ->assertSee('Riprova');
});

test('reply() senza un messaggio appena inviato non chiama il backend', function () {
    Message::factory()->for($this->conversation)->create(['content' => 'Domanda senza risposta']);

    openChat()->call('reply');

    Http::assertNothingSent();
});

test('oltre il limite di messaggi al minuto la chat chiede di aspettare', function () {
    Config::set('stapply.chat_messages_per_minute', 2);
    fakeBackend(['POST agents/agente-di-prova/chat' => Http::response(contract('chat.200'))]);

    $chat = openChat();

    foreach (['Prima domanda', 'Seconda domanda'] as $question) {
        $chat->set('message', $question)->call('send')->assertHasNoErrors()->call('reply');
    }

    $chat->set('message', 'Terza domanda')
        ->call('send')
        ->assertHasErrors('message')
        ->assertSee('Hai mandato troppi messaggi in poco tempo')
        ->assertSet('message', 'Terza domanda')
        ->assertSet('awaitingReply', false);

    expect($this->conversation->messages()->where('role', MessageRole::User)->count())->toBe(2);
    Http::assertSentCount(2);
});

test('anche Riprova rispetta il limite', function () {
    Config::set('stapply.chat_messages_per_minute', 1);
    fakeBackend(['POST agents/agente-di-prova/chat' => Http::response(status: 502)]);

    openChat()
        ->set('message', 'Massimale cena a Milano?')
        ->call('send')
        ->call('reply')
        ->call('retry')
        ->assertSet('awaitingReply', false)
        ->assertSee('Hai mandato troppi messaggi in poco tempo');

    Http::assertSentCount(1);
});

test('eliminare una chat cancella la sessione nel backend, poi i messaggi', function () {
    Message::factory()->for($this->conversation)->create();
    $path = "agents/agente-di-prova/sessions/{$this->conversation->session_id}";
    fakeBackend(["DELETE {$path}" => Http::response(status: 204)]);

    openChat()
        ->call('delete')
        ->assertRedirect(route('agents.chat', $this->agent));

    $this->assertModelMissing($this->conversation);
    expect(Message::count())->toBe(0);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === "http://backend.test/{$path}");
});

test('se il backend non elimina la sessione la chat resta', function () {
    Http::fake(Http::failedConnection());

    openChat()
        ->call('delete')
        ->assertSet('deleteError', 'Il backend non è raggiungibile o non ha risposto in tempo: riprova tra poco.')
        ->assertNoRedirect();

    $this->assertModelExists($this->conversation);
});

test("eliminando l'agente spariscono anche le sue chat", function () {
    Message::factory()->for($this->conversation)->create();

    $this->agent->delete();

    $this->assertModelMissing($this->conversation);
    expect(Message::count())->toBe(0);
});

test('si chatta anche con gli agenti demo', function () {
    $demo = Agent::factory()->demo()->for($this->user)->create(['backend_id' => 'demo-valtesa']);
    $conversation = Conversation::factory()->for($demo)->create();
    fakeBackend(['POST agents/demo-valtesa/chat' => Http::response(contract('chat.200'))]);

    Livewire::test(Chat::class, ['agent' => $demo, 'conversation' => $conversation])
        ->set('message', 'Massimale cena a Milano?')
        ->call('send')
        ->call('reply')
        ->assertSee('40 € dal 1° luglio 2026');
});
