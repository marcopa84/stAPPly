<?php

use App\Livewire\Settings\DeleteUserForm;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agents = Agent::factory()->for($this->user)->count(2)->create();
    Conversation::factory()->for($this->agents[0])->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

test("eliminando l'account si cancellano nel backend i dati di ogni agente, poi l'utente", function () {
    fakeBackend(['DELETE agents/*' => Http::response(status: 204)]);

    Livewire::test(DeleteUserForm::class)
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect(agentsDeleted())->toEqualCanonicalizing($this->agents->pluck('backend_id')->all())
        ->and($this->user->fresh())->toBeNull()
        ->and(Agent::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);

    $this->assertGuest();
});

test("se il backend non cancella un agente l'account resta, con il motivo, e si può riprovare", function () {
    fakeBackend([
        'DELETE agents/*' => Http::sequence()
            ->push(status: 204)
            ->pushFailedConnection()
            ->push(status: 204),
    ]);

    $form = Livewire::test(DeleteUserForm::class)
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors('password')
        ->assertSee("L'account non è stato eliminato. Il backend non è raggiungibile")
        ->assertNoRedirect();

    // Il primo agente non c'è più da nessuna parte; il secondo resta, con i suoi dati nel backend.
    expect($this->user->fresh())->not->toBeNull()
        ->and($this->user->agents()->pluck('backend_id')->all())->toBe([$this->agents[1]->backend_id]);

    $this->assertAuthenticatedAs($this->user);

    $form->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');

    // Il secondo agente due volte: il tentativo fallito e quello riuscito.
    expect($this->user->fresh())->toBeNull()
        ->and(agentsDeleted())->toBe([$this->agents[0]->backend_id, $this->agents[1]->backend_id, $this->agents[1]->backend_id]);
});

test('con la password sbagliata non parte nessuna cancellazione', function () {
    Livewire::test(DeleteUserForm::class)
        ->set('password', 'sbagliata')
        ->call('deleteUser')
        ->assertHasErrors('password');

    expect($this->user->agents()->count())->toBe(2);

    Http::assertNothingSent();
});
