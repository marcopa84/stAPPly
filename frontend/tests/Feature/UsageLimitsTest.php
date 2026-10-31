<?php

use App\Livewire\Agents\Chat;
use App\Livewire\Agents\Create;
use App\Livewire\Agents\Documents;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * I limiti di costo per utente valgono anche per chi non è nella demo: la chiave
 * OpenAI è del progetto, e la registrazione è aperta.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->for($this->user)->create(['backend_id' => 'agente-di-prova']);

    fakeBackend([
        'GET agents/*/documents' => Http::response(['documents' => []]),
        'POST agents/*/documents' => Http::response(contract('documents.upload.201'), 201),
    ]);
});

function uploadAs(User $user, Agent $agent): Testable
{
    return Livewire::actingAs($user)
        ->test(Documents::class, ['agent' => $agent])
        ->set('file', UploadedFile::fake()->createWithContent('regolamento.txt', 'testo'))
        ->call('upload');
}

function uploadsDone(): int
{
    return Http::recorded()->filter(fn (array $pair) => $pair[0]->method() === 'POST')->count();
}

test('un utente registrato non crea più agenti del limite', function () {
    Config::set('stapply.max_agents', 1);

    $this->actingAs($this->user)
        ->get(route('agents.create'))
        ->assertSee('Puoi avere al massimo 1 agenti: eliminane uno per crearne un altro.');

    Livewire::actingAs($this->user)
        ->test(Create::class)
        ->set('form.name', 'Il secondo')
        ->set('form.instructions', 'Rispondi in italiano.')
        ->call('save')
        ->assertNoRedirect();

    expect($this->user->agents()->count())->toBe(1);
});

test('i caricamenti all\'ora si contano per utente, anche per i registrati', function () {
    Config::set('stapply.uploads_per_hour', 1);

    uploadAs($this->user, $this->agent)->assertHasNoErrors();

    uploadAs($this->user, $this->agent)
        ->assertHasErrors('file')
        ->assertSee('Hai caricato troppi file nell&#039;ultima ora: riprova tra 60 minuti.', false);

    // Un altro utente ha il suo conto.
    $other = User::factory()->create();
    uploadAs($other, Agent::factory()->for($other)->create())->assertHasNoErrors();

    expect(uploadsDone())->toBe(2);
});

test('e anche i caricamenti al giorno', function () {
    Config::set('stapply.uploads_per_day', 1);

    uploadAs($this->user, $this->agent)->assertHasNoErrors();

    uploadAs($this->user, $this->agent)
        ->assertHasErrors('file')
        ->assertSee('Hai caricato tutti i file a disposizione per oggi: riprova tra 24 ore.');

    expect(uploadsDone())->toBe(1);
});

test('la chat ha un tetto di messaggi al giorno per utente', function () {
    Config::set('stapply.chat_messages_per_day', 1);
    $conversation = Conversation::factory()->for($this->agent)->create(['user_id' => $this->user->id]);

    $chat = Livewire::actingAs($this->user)
        ->test(Chat::class, ['agent' => $this->agent, 'conversation' => $conversation])
        ->set('message', 'Prima domanda')
        ->call('send')
        ->assertHasNoErrors();

    $chat->set('message', 'Seconda domanda')
        ->call('send')
        ->assertHasErrors('message')
        ->assertSee('Hai mandato tutti i messaggi a disposizione per oggi: riprova tra 24 ore.');

    expect($conversation->messages()->count())->toBe(1);
});

test('un caricamento fermato da un limite non consuma gli altri', function () {
    Config::set('stapply.uploads_per_hour', 2);
    Config::set('stapply.demo.uploads_per_hour', 1);

    // Un'altra sandbox riempie il limite comune a tutte.
    $other = User::factory()->demoSandbox()->create();
    uploadAs($other, Agent::factory()->for($other)->create())->assertHasNoErrors();

    $sandbox = User::factory()->demoSandbox()->create();
    $copy = Agent::factory()->for($sandbox)->create();

    foreach ([1, 2] as $attempt) {
        uploadAs($sandbox, $copy)->assertHasErrors('file')->assertSee('La demo ha raggiunto il limite di caricamenti');
    }

    // Con il limite comune di nuovo libero, i due caricamenti dell'ora sono ancora tutti suoi.
    Config::set('stapply.demo.uploads_per_hour', 10);

    uploadAs($sandbox, $copy)->assertHasNoErrors();
    uploadAs($sandbox, $copy)->assertHasNoErrors();

    expect(uploadsDone())->toBe(3);
});
