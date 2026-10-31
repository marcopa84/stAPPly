<?php

use App\Livewire\Agents\Edit;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Demo\DemoSandbox;
use App\Support\DemoAccount;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

const SANDBOX_NOTICE = 'Sei nella demo: puoi modificare tutto. Al prossimo accesso riparti dai valori iniziali.';

beforeEach(function () {
    $this->seed(DemoSeeder::class);
    $this->template = DemoAccount::template();
});

describe('fine della sandbox', function () {
    test('il logout chiude subito la sandbox', function () {
        $sandbox = User::factory()->demoSandbox()->create();

        $this->actingAs($sandbox)->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();

        expect($sandbox->fresh()->demoSandboxExpired())->toBeTrue();
    });

    test('una sandbox scaduta ancora collegata torna alla home, che spiega come ripartire', function () {
        $sandbox = User::factory()->expiredDemoSandbox()->create();

        $this->actingAs($sandbox)
            ->get(route('agents.index'))
            ->assertRedirect(route('home', ['demo' => 'terminata']));

        $this->assertGuest();

        $this->get(route('home', ['demo' => 'terminata']))
            ->assertOk()
            ->assertSee(DemoAccount::ENDED_MESSAGE);
    });

    test('una sessione rimasta aperta sul template si chiude: il template non si usa più', function () {
        $this->actingAs($this->template)
            ->get(route('agents.index'))
            ->assertRedirect(route('home', ['demo' => 'terminata']));

        $this->assertGuest();
    });

    test('le sandbox aperte e gli altri utenti passano', function (User $user) {
        $this->actingAs($user)->get(route('agents.index'))->assertOk();
    })->with([
        'sandbox aperta' => fn () => User::factory()->demoSandbox()->create(),
        'utente registrato' => fn () => User::factory()->create(),
    ]);

    test('anche quando il prune ha già cancellato la sandbox, la sessione torna alla home che spiega come ripartire', function () {
        fakeBackend([
            'POST agents/*/clone' => Http::response(contract('agents.clone.201'), 201),
            'DELETE agents/*' => Http::response(status: 204),
        ]);

        demoLogin()->assertRedirect(route('dashboard', absolute: false));
        $sandbox = auth()->user();

        $this->travel(3)->hours();
        expect(app(DemoSandbox::class)->prune())->toBe(1);

        // La richiesta dopo rilegge l'utente dalla sessione, come farebbe il browser.
        $this->app['auth']->forgetGuards();

        $this->get(route('agents.index'))->assertRedirect(route('home', ['demo' => 'terminata']));

        $this->assertGuest();

        expect($sandbox->fresh())->toBeNull();

        // Il segno della demo è andato via con la sessione: niente giri a vuoto.
        $this->get(route('login'))->assertOk();
    });

    test('senza il parametro la home non parla di demo terminata', function () {
        $this->get(route('home'))->assertOk()->assertDontSee(DemoAccount::ENDED_MESSAGE);
    });
});

describe('stapply:prune-demo', function () {
    beforeEach(function () {
        $this->active = User::factory()->demoSandbox()->create();
        $this->activeAgent = Agent::factory()->for($this->active)->create();

        $this->expired = User::factory()->expiredDemoSandbox()->create();
        $this->expiredAgents = Agent::factory()->for($this->expired)->count(2)->create();

        $conversation = Conversation::factory()->for($this->expiredAgents[0])->create(['user_id' => $this->expired->id]);
        $conversation->ask('Quanti giorni di ferie ho?');
    });

    test('cancella dal backend solo gli agenti delle sandbox scadute, poi utente, agenti, chat e messaggi', function () {
        fakeBackend(['DELETE agents/*' => Http::response(status: 204)]);

        $this->artisan('stapply:prune-demo')
            ->expectsOutputToContain('Sandbox scadute cancellate: 1.')
            ->assertSuccessful();

        expect(agentsDeleted())->toEqualCanonicalizing($this->expiredAgents->pluck('backend_id')->all())
            ->and($this->expired->fresh())->toBeNull()
            ->and(Agent::whereIn('id', $this->expiredAgents->modelKeys())->exists())->toBeFalse()
            ->and(Conversation::count())->toBe(0)
            ->and(Message::count())->toBe(0)
            ->and($this->active->fresh())->not->toBeNull()
            ->and($this->activeAgent->fresh())->not->toBeNull()
            ->and($this->template->agents()->pluck('backend_id')->sort()->values()->all())->toBe(['demo-valdoria', 'demo-valtesa']);
    });

    test('se il backend non cancella, la sandbox resta per il giro dopo', function () {
        Log::spy();
        fakeBackend(['DELETE agents/*' => Http::sequence()
            ->push(contract('error'), 500)
            ->push(status: 204)
            ->push(status: 204)]);

        $this->artisan('stapply:prune-demo')
            ->expectsOutputToContain('Sandbox da riprovare al prossimo giro: 1')
            ->assertFailed();

        expect($this->expired->fresh())->not->toBeNull()
            ->and($this->expired->agents()->count())->toBe(2);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'si riprova al prossimo giro'))
            ->once();

        $this->artisan('stapply:prune-demo')->assertSuccessful();

        expect($this->expired->fresh())->toBeNull();
    });

    test('non cancella mai un agente del template, nemmeno finito per errore in una sandbox', function () {
        Log::spy();
        fakeBackend(['DELETE agents/*' => Http::response(status: 204)]);

        // Un errore che sposta un agente demo in una sandbox: la guardia lo riconosce dal DB.
        $demo = Agent::where('backend_id', 'demo-valtesa')->sole();
        $demo->forceFill(['user_id' => $this->expired->id])->save();

        $this->artisan('stapply:prune-demo')->assertFailed();

        expect(agentsDeleted())->not->toContain('demo-valtesa')
            ->and($demo->fresh())->not->toBeNull()
            ->and($this->expired->fresh())->not->toBeNull();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => $context['backend_id'] === 'demo-valtesa')
            ->once();
    });

    test('gira ogni ora', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'stapply:prune-demo'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('0 * * * *');
    });
});

describe('dentro la sandbox', function () {
    test("l'avviso della demo compare solo nelle sandbox, con l'ora di scadenza", function () {
        $sandbox = User::factory()->demoSandbox()->create();

        $this->actingAs($sandbox)
            ->get(route('agents.index'))
            ->assertSee(SANDBOX_NOTICE)
            ->assertSee('datetime="'.$sandbox->demo_expires_at->toIso8601String().'"', false);

        $this->actingAs(User::factory()->create())
            ->get(route('agents.index'))
            ->assertDontSee(SANDBOX_NOTICE);
    });

    test('una sandbox non vede agenti e chat di un\'altra', function () {
        $mine = User::factory()->demoSandbox()->create();
        $other = User::factory()->demoSandbox()->create();
        $otherAgent = Agent::factory()->for($other)->create(['name' => 'Copia di un altro visitatore']);
        $otherChat = Conversation::factory()->for($otherAgent)->create(['user_id' => $other->id]);

        $this->actingAs($mine);

        $this->get(route('agents.index'))->assertOk()->assertDontSee('Copia di un altro visitatore');
        $this->get(route('agents.show', $otherAgent))->assertForbidden();
        $this->get(route('agents.chat', ['agent' => $otherAgent, 'conversation' => $otherChat]))->assertForbidden();

        Http::assertNothingSent();
    });

    test('la copia di un agente demo si modifica e si elimina come un agente proprio', function () {
        $sandbox = User::factory()->demoSandbox()->create();
        $copy = Agent::factory()->for($sandbox)->create(['name' => 'Assistente Valtesa']);
        fakeBackend(['DELETE agents/'.$copy->backend_id => Http::response(status: 204)]);

        $edit = Livewire::actingAs($sandbox)
            ->test(Edit::class, ['agent' => $copy])
            ->set('form.instructions', 'Rispondi solo sulle trasferte.')
            ->call('save')
            ->assertHasNoErrors();

        expect($copy->fresh()->instructions)->toBe('Rispondi solo sulle trasferte.');

        $edit->call('delete')->assertRedirect(route('agents.index'));

        expect($copy->fresh())->toBeNull()
            ->and(agentsDeleted())->toBe([$copy->backend_id]);
    });
});
