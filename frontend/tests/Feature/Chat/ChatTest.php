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
        ->assertSee('Di cosa vuoi parlare con Assistente Valtesa?');

    Http::assertNothingSent();
});

test("senza conversazione nell'URL si apre una chat nuova, pronta per scrivere", function () {
    $this->get(route('agents.chat', $this->agent))
        ->assertOk()
        ->assertSee('Nuova chat')
        ->assertSee('Di cosa vuoi parlare con Assistente Valtesa?')
        ->assertSee('Invia')
        ->assertSee('Trasferte');

    // Aprire la pagina non crea niente: la chat nasce con la prima domanda.
    expect(Conversation::count())->toBe(1);
    Http::assertNothingSent();
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

test('la prima domanda apre una chat nuova con un titolo provvisorio, e la risposta parte da sola', function () {
    fakeBackend(['POST agents/agente-di-prova/chat' => Http::response(contract('chat.200'))]);
    $question = "Qual è il massimale per una cena in trasferta a Milano, e vale anche per Roma?\nGrazie";

    $newChat = Livewire::test(Chat::class, ['agent' => $this->agent])
        ->set('message', $question)
        ->call('send')
        ->assertHasNoErrors();

    $conversation = Conversation::where('id', '!=', $this->conversation->id)->sole();
    $newChat->assertRedirect(route('agents.chat', ['agent' => $this->agent, 'conversation' => $conversation]));

    expect($conversation->user_id)->toBe($this->user->id)
        ->and($conversation->title)->toBe('Qual è il massimale per una cena in trasferta a…')
        ->and($conversation->titled_at)->toBeNull()
        ->and($conversation->session_id)->toHaveLength(26)->toMatch(SESSION_ID_PATTERN)
        ->and($conversation->session_id)->toBe(strtolower($conversation->session_id))
        ->and($conversation->messages()->sole()->content)->toBe($question);
    Http::assertNothingSent();

    // All'indirizzo della chat la domanda aspetta la risposta, che parte con reply().
    Livewire::test(Chat::class, ['agent' => $this->agent, 'conversation' => $conversation])
        ->assertSet('awaitingReply', true)
        ->assertSeeHtml('x-init="$wire.reply().catch(() => $wire.replyFailed())"')
        ->call('reply')
        ->assertSet('awaitingReply', false);

    Http::assertSent(fn (Request $request) => $request['session_id'] === $conversation->session_id
        && $request['message'] === $question);
    expect($conversation->messages()->count())->toBe(2);
});

test('riaprendo una chat dallo storico il backend riceve la stessa sessione (criterio 1)', function () {
    fakeBackend(['POST agents/agente-di-prova/chat' => Http::response(contract('chat.200'))]);

    openChat()->set('message', 'Quanti giorni di ferie ho?')->call('send')->call('reply');

    // Chiusa e riaperta dallo storico: stessa conversazione, la domanda di prima è lì.
    $this->get(route('agents.chat', $this->agent))
        ->assertSee(route('agents.chat', ['agent' => $this->agent, 'conversation' => $this->conversation]));

    openChat()
        ->assertSee('Quanti giorni di ferie ho?')
        ->set('message', 'E quanti me ne restano?')
        ->call('send')
        ->call('reply');

    $sessions = Http::recorded()->map(fn (array $pair) => $pair[0]->data()['session_id'])->unique();

    expect($sessions->all())->toBe([$this->conversation->session_id])
        ->and(Conversation::count())->toBe(1);
});

test('un messaggio vuoto non parte e non apre chat', function () {
    Livewire::test(Chat::class, ['agent' => $this->agent])
        ->set('message', '   ')
        ->call('send')
        ->assertHasErrors(['message' => 'required']);

    openChat()
        ->set('message', '   ')
        ->call('send')
        ->assertHasErrors(['message' => 'required'])
        ->assertSet('awaitingReply', false);

    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(0);
    Http::assertNothingSent();
});

test('oltre il limite di messaggi la chat nuova non nasce', function () {
    Config::set('stapply.chat_messages_per_minute', 1);
    fakeBackend(['POST agents/agente-di-prova/chat' => Http::response(contract('chat.200'))]);

    openChat()->set('message', 'Prima domanda')->call('send')->call('reply');

    Livewire::test(Chat::class, ['agent' => $this->agent])
        ->set('message', 'Seconda domanda, in una chat nuova')
        ->call('send')
        ->assertHasErrors('message')
        ->assertNoRedirect();

    expect(Conversation::count())->toBe(1);
});

/**
 * Una chat con il titolo provvisorio, una domanda e la sua risposta.
 */
function provisionalChat(): Conversation
{
    $conversation = Conversation::factory()->for(test()->agent)->create([
        'title' => 'Quante note spese sono in attesa…',
        'titled_at' => null,
    ]);
    Message::factory()->for($conversation)->create(['content' => 'Quante note spese sono in attesa?', 'reply_started_at' => now()]);
    Message::factory()->for($conversation)->create(['role' => MessageRole::Assistant, 'content' => 'Sono 56.']);

    return $conversation;
}

test('dopo la prima risposta il modello scrive il titolo, una volta sola', function () {
    fakeBackend(['POST titles' => Http::response(contract('titles.200'))]);
    $conversation = provisionalChat();

    Livewire::test(Chat::class, ['agent' => $this->agent, 'conversation' => $conversation])
        ->call('nameConversation')
        ->call('nameConversation')
        ->assertSee('Note spese in attesa di approvazione');

    expect($conversation->fresh())
        ->title->toBe('Note spese in attesa di approvazione')
        ->titled_at->not->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://backend.test/titles'
        && $request['message'] === 'Quante note spese sono in attesa?'
        && $request['answer'] === 'Sono 56.');
});

test('la risposta alla prima domanda chiede il titolo, quella di una chat già intitolata no', function () {
    fakeBackend(['POST agents/agente-di-prova/chat' => Http::response(contract('chat.200'))]);
    $conversation = Conversation::factory()->for($this->agent)->create(['titled_at' => null]);

    Livewire::test(Chat::class, ['agent' => $this->agent, 'conversation' => $conversation])
        ->set('message', 'Prima domanda')
        ->call('send')
        ->call('reply')
        ->assertJs('$wire.nameConversation()');

    openChat()
        ->set('message', 'Altra domanda')
        ->call('send')
        ->call('reply')
        ->assertNoJs();
});

test("se l'utente rinomina la chat mentre arriva il titolo, vince il suo nome", function () {
    $conversation = provisionalChat();
    fakeBackend(['POST titles' => function () use ($conversation) {
        $conversation->fresh()->rename('Note spese di luglio');

        return Http::response(contract('titles.200'));
    }]);

    Livewire::test(Chat::class, ['agent' => $this->agent, 'conversation' => $conversation])
        ->call('nameConversation');

    expect($conversation->fresh()->title)->toBe('Note spese di luglio');
});

test('se il backend non scrive il titolo resta quello provvisorio', function () {
    fakeBackend(['POST titles' => Http::response(['detail' => 'Il modello non risponde.'], 502)]);
    $conversation = provisionalChat();

    Livewire::test(Chat::class, ['agent' => $this->agent, 'conversation' => $conversation])
        ->call('nameConversation')
        ->assertHasNoErrors();

    expect($conversation->fresh()->title)->toBe('Quante note spese sono in attesa…');
});

test('una chat già intitolata, o senza risposta, non chiede il titolo', function () {
    $conversation = provisionalChat();
    $conversation->rename('Note spese');

    Livewire::test(Chat::class, ['agent' => $this->agent, 'conversation' => $conversation])->call('nameConversation');
    openChat()->call('nameConversation');

    Http::assertNothingSent();
});

test('la chat si rinomina dal titolo, e il nuovo nome resta', function () {
    openChat()
        ->call('startRename')
        ->assertSet('newTitle', 'Trasferte')
        ->set('newTitle', '  Trasferte a Milano ')
        ->call('rename')
        ->assertHasNoErrors()
        ->assertSet('newTitle', null)
        ->assertSee('Trasferte a Milano');

    expect($this->conversation->fresh())
        ->title->toBe('Trasferte a Milano')
        ->titled_at->not->toBeNull();
});

test('un titolo vuoto o troppo lungo non si salva, e Annulla lascia quello di prima', function (string $title, string $rule) {
    openChat()
        ->call('startRename')
        ->set('newTitle', $title)
        ->call('rename')
        ->assertHasErrors(['newTitle' => $rule])
        ->call('cancelRename')
        ->assertSet('newTitle', null);

    expect($this->conversation->fresh()->title)->toBe('Trasferte');
})->with([
    'vuoto' => ['   ', 'required'],
    'troppo lungo' => [str_repeat('a', 101), 'max'],
]);

test('due chat possono avere lo stesso titolo', function () {
    $other = Conversation::factory()->for($this->agent)->create(['title' => 'Note spese']);

    openChat()->call('startRename')->set('newTitle', 'Note spese')->call('rename')->assertHasNoErrors();

    expect(Conversation::where('title', 'Note spese')->count())->toBe(2)
        ->and($other->fresh()->session_id)->not->toBe($this->conversation->fresh()->session_id);
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

test('riaprendo la pagina, la domanda già mandata e rimasta senza risposta si può rimandare', function () {
    Message::factory()->for($this->conversation)->create(['content' => 'Massimale cena a Milano?', 'reply_started_at' => now()]);

    $this->get(route('agents.chat', ['agent' => $this->agent, 'conversation' => $this->conversation]))
        ->assertOk()
        ->assertSee('Questa domanda è rimasta senza risposta')
        ->assertSee('Riprova');
});

test('reply() senza un messaggio appena inviato non chiama il backend', function () {
    Message::factory()->for($this->conversation)->create(['content' => 'Domanda senza risposta', 'reply_started_at' => now()]);

    openChat()->assertSet('awaitingReply', false)->call('reply');

    Http::assertNothingSent();
});

test('una domanda salvata e mai mandata al backend parte da sola, una volta', function () {
    fakeBackend(['POST agents/agente-di-prova/chat' => Http::response(contract('chat.200'))]);
    Message::factory()->for($this->conversation)->create(['content' => 'Domanda rimasta lì']);

    openChat()->assertSet('awaitingReply', true)->call('reply')->call('reply');
    openChat()->assertSet('awaitingReply', false);

    Http::assertSentCount(1);
});

test('lo snapshot di send() rimandato non richiama il backend: un messaggio contato arriva al modello una volta', function () {
    fakeBackend([
        'POST agents/agente-di-prova/chat' => Http::sequence()
            ->push(['detail' => 'Il provider del modello non risponde: riprova tra poco.'], 502)
            ->push(contract('chat.200')),
    ]);

    $chat = openChat()->set('message', 'Massimale cena a Milano?')->call('send');
    $afterSend = $chat->snapshot;

    $chat->call('reply')->assertSet('error', 'Il provider del modello non risponde: riprova tra poco.');

    // Il browser rimanda la richiesta di reply() con lo snapshot firmato ricevuto dopo send().
    $chat->snapshot = $afterSend;
    $chat->call('reply');

    Http::assertSentCount(1);

    // Riprova conta un altro messaggio nel limite, e solo allora la domanda riparte.
    $chat->call('retry')->call('reply')->assertSee('40 € dal 1° luglio 2026');

    Http::assertSentCount(2);
    expect($this->conversation->messages()->pluck('role')->all())->toBe([MessageRole::User, MessageRole::Assistant]);
});

test('una domanda si prende in carico una volta sola, finché Riprova non la rimette in gioco', function () {
    $question = $this->conversation->ask('Massimale cena a Milano?');

    expect($question->claimForReply())->toBeTrue()
        ->and($question->claimForReply())->toBeFalse();

    $question->releaseForReply();

    expect($question->claimForReply())->toBeTrue();
});

test('se la richiesta di reply() fallisce prima di una risposta, la pagina offre Riprova', function () {
    openChat()
        ->set('message', 'Massimale cena a Milano?')
        ->call('send')
        // È il browser a chiamare replyFailed() quando la promise di reply() fallisce (500, 419, rete).
        ->assertSeeHtml('x-init="$wire.reply().catch(() => $wire.replyFailed())"')
        ->assertDontSee('Riprova')
        ->call('replyFailed')
        ->assertSet('awaitingReply', false)
        ->assertSee('La risposta non è arrivata: puoi riprovare.')
        ->assertSee('Riprova');

    Http::assertNothingSent();
});

test('replyFailed() senza una risposta attesa non cambia niente', function () {
    Message::factory()->for($this->conversation)->create(['content' => 'Domanda senza risposta', 'reply_started_at' => now()]);

    openChat()
        ->call('replyFailed')
        ->assertSet('error', null)
        ->assertSee('Questa domanda è rimasta senza risposta');
});

test('mentre aspetta la risposta la textarea resta a fuoco: readonly, non disabled', function () {
    preg_match('/<textarea[^>]*>/', openChat()->html(), $textarea);

    // Un campo disattivato perde il focus; readonly no, ma allora l'Invio va fermato a mano.
    expect($textarea[0])
        ->toContain('wire:loading.attr="readonly"')
        ->not->toContain('wire:loading.attr="disabled"')
        ->toContain('if (! $el.readOnly) $el.form.requestSubmit()');
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
