<?php

use App\Livewire\Agents\Chat;
use App\Livewire\Agents\SqlConsole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->agent = Agent::factory()->for($this->owner)->create(['backend_id' => 'agente-di-prova']);
    $this->conversation = Conversation::factory()->for($this->agent)->create();
    $this->stranger = User::factory()->create();
});

test("un altro utente non apre la chat dell'agente né le sue conversazioni", function () {
    $this->actingAs($this->stranger);

    $this->get(route('agents.chat', $this->agent))->assertForbidden();

    $this->get(route('agents.chat', ['agent' => $this->agent, 'conversation' => $this->conversation]))
        ->assertForbidden();
});

test('un altro utente non monta i componenti della chat e della console', function () {
    Livewire::actingAs($this->stranger)
        ->test(Chat::class, ['agent' => $this->agent, 'conversation' => $this->conversation])
        ->assertForbidden();

    Livewire::actingAs($this->stranger)
        ->test(SqlConsole::class, ['agent' => $this->agent])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('le azioni ricontrollano i permessi a ogni richiesta', function (string $action, array $data) {
    $chat = Livewire::actingAs($this->owner)
        ->test(Chat::class, ['agent' => $this->agent, 'conversation' => $this->conversation]);

    $this->actingAs($this->stranger);

    foreach ($data as $property => $value) {
        $chat->set($property, $value);
    }

    $chat->call($action)->assertForbidden();

    expect(Message::count())->toBe(0);
    $this->assertModelExists($this->conversation);
    Http::assertNothingSent();
})->with([
    'invio' => ['send', ['message' => 'Ciao']],
    'nuova chat' => ['open', ['title' => 'Intrusione']],
    'eliminazione' => ['delete', []],
]);

test("una conversazione di un altro agente non si apre cambiando l'agente nell'URL", function () {
    $otherAgent = Agent::factory()->for($this->owner)->create();

    $this->actingAs($this->owner)
        ->get(route('agents.chat', ['agent' => $otherAgent, 'conversation' => $this->conversation]))
        ->assertNotFound();
});

test('un session_id inesistente dà 404', function () {
    $this->actingAs($this->owner)
        ->get(route('agents.chat', ['agent' => $this->agent, 'conversation' => '01jbx3k6v2c8m0q4w5t7y9z1ab']))
        ->assertNotFound();
});

test('nessuno legge o scrive nella conversazione di un altro utente', function () {
    // Non nasce dall'interfaccia, ma la policy della conversazione non si fida dell'agente.
    $foreign = Conversation::factory()->for($this->agent)->create(['user_id' => $this->stranger->id]);
    Message::factory()->for($foreign)->create(['content' => 'Messaggio privato']);

    $this->actingAs($this->owner)
        ->get(route('agents.chat', ['agent' => $this->agent, 'conversation' => $foreign]))
        ->assertForbidden();

    $this->get(route('agents.chat', $this->agent))
        ->assertOk()
        ->assertDontSee($foreign->title);
});

test('agente, conversazione e stato della risposta non si cambiano dal browser', function (string $property) {
    $other = Conversation::factory()->create();
    $values = ['agent' => $other->agent, 'conversation' => $other, 'awaitingReply' => true, 'error' => 'finto'];

    expect(fn () => Livewire::actingAs($this->owner)
        ->test(Chat::class, ['agent' => $this->agent, 'conversation' => $this->conversation])
        ->set($property, $values[$property]))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    Http::assertNothingSent();
})->with(['agent', 'conversation', 'awaitingReply', 'error']);

test("l'agente della console SQL non si cambia dal browser", function () {
    fakeBackend(['GET agents/agente-di-prova/tables' => Http::response(['tables' => []])]);

    expect(fn () => Livewire::actingAs($this->owner)
        ->test(SqlConsole::class, ['agent' => $this->agent])
        ->set('agent', Agent::factory()->create()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
