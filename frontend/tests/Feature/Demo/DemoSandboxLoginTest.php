<?php

use App\Models\Agent;
use App\Models\User;
use App\Services\Demo\DemoUnavailableException;
use App\Support\DemoAccount;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(DemoSeeder::class);
    $this->template = DemoAccount::template();
});

/** Il backend che copia gli agenti: risponde come l'esempio del contratto. */
function cloneOk(): array
{
    return ['POST agents/*/clone' => Http::response(contract('agents.clone.201'), 201)];
}

/**
 * Lo stato del template dopo il seed: utente e agenti demo, per controllare che una
 * sandbox non lo tocchi.
 *
 * @return array<string, mixed>
 */
function templateSnapshot(): array
{
    $template = DemoAccount::template();

    return [
        'user' => $template?->only(['id', 'name', 'email', 'password', 'demo_expires_at']),
        'agents' => Agent::query()->where('is_demo', true)->oldest('id')->get()
            ->map(fn (Agent $agent) => $agent->only(['id', 'user_id', 'backend_id', 'name', 'instructions', 'is_demo']))
            ->all(),
    ];
}

/**
 * Le sandbox, dalla prima aperta.
 *
 * @return Collection<int, User>
 */
function sandboxes(): Collection
{
    return User::query()->whereNotNull('demo_expires_at')->oldest('id')->get();
}

test('le credenziali demo aprono una sandbox nuova con la copia di ogni agente demo', function () {
    fakeBackend(cloneOk());

    demoLogin()->assertRedirect(route('dashboard', absolute: false));

    $sandbox = sandboxes()->sole();
    $copies = $sandbox->agents()->oldest('id')->get();

    $this->assertAuthenticatedAs($sandbox);

    expect($sandbox)
        ->name->toBe('Visitatore demo')
        ->email->toStartWith('demo-')->toEndWith('@'.DemoAccount::SANDBOX_DOMAIN)
        ->and($sandbox->hasVerifiedEmail())->toBeTrue()
        ->and($sandbox->password)->toBe($this->template->password)
        ->and($sandbox->demo_expires_at->diffInMinutes(now()->addMinutes(120), absolute: true))->toBeLessThan(1);

    // Le copie sono del visitatore: stessi nomi e istruzioni, ma modificabili in tutto.
    expect($copies->pluck('name')->all())->toBe(['Assistente Valtesa', 'Biblioteca di Valdoria'])
        ->and($copies->pluck('instructions')->all())->toBe([DemoSeeder::VALTESA_INSTRUCTIONS, DemoSeeder::VALDORIA_INSTRUCTIONS])
        ->and($copies->every(fn (Agent $copy) => ! $copy->is_demo))->toBeTrue()
        ->and($copies->pluck('backend_id')->all())->each->toMatch('/^[0-9a-z]{26}$/');

    expect(clonesSent())->toBe([
        $copies[0]->backend_id => 'demo-valtesa',
        $copies[1]->backend_id => 'demo-valdoria',
    ]);
});

test('un login demo apre una sola sandbox e chiede due copie, anche se Fortify controlla le credenziali due volte', function () {
    fakeBackend(cloneOk());

    demoLogin();

    expect(sandboxes())->toHaveCount(1)
        ->and(Agent::count())->toBe(4);

    Http::assertSentCount(2);
});

test('due accessi demo aprono due sandbox distinte, ognuna con le sue copie', function () {
    fakeBackend(cloneOk());

    demoLogin();

    // Un secondo visitatore, da un altro browser: la prima sandbox resta aperta.
    $this->flushSession();
    $this->app['auth']->forgetGuards();

    demoLogin();

    [$first, $second] = sandboxes()->all();
    $firstIds = $first->agents()->pluck('backend_id');
    $secondIds = $second->agents()->pluck('backend_id');

    $this->assertAuthenticatedAs($second);

    expect($first->demoSandboxExpired())->toBeFalse()
        ->and($second->demoSandboxExpired())->toBeFalse()
        ->and($first->email)->not->toBe($second->email)
        ->and($firstIds->intersect($secondIds))->toBeEmpty()
        ->and(clonesSent())->toBe([
            $firstIds[0] => 'demo-valtesa',
            $firstIds[1] => 'demo-valdoria',
            $secondIds[0] => 'demo-valtesa',
            $secondIds[1] => 'demo-valdoria',
        ]);
});

test('il template resta com\'era e il backend non riceve niente sui suoi agenti', function () {
    $before = templateSnapshot();
    fakeBackend(cloneOk());

    demoLogin();

    expect(templateSnapshot())->toBe($before);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/agents/demo-'));
});

test('con il backend giù il login demo fallisce senza lasciare utenti né copie', function () {
    fakeBackend(['POST agents/*/clone' => Http::failedConnection()]);

    demoLogin()->assertSessionHasErrors([
        'email' => DemoUnavailableException::backend(new RuntimeException)->getMessage(),
    ]);

    $this->assertGuest();

    expect(User::count())->toBe(1)
        ->and(Agent::count())->toBe(2)
        ->and(agentsDeleted())->toBe([]);
});

test('se una copia fallisce si cancella dal backend solo quella già fatta in questo tentativo', function () {
    fakeBackend([
        'POST agents/*/clone' => Http::sequence()
            ->push(contract('agents.clone.201'), 201)
            ->push(contract('error'), 500),
        'DELETE agents/*' => Http::response(status: 204),
    ]);

    demoLogin()->assertSessionHasErrors('email');

    $this->assertGuest();

    expect(User::count())->toBe(1)
        ->and(Agent::count())->toBe(2)
        ->and(agentsDeleted())->toBe([array_key_first(clonesSent())]);
});

test('se il backend non cancella nemmeno la copia fatta, la sandbox resta scaduta per il prune', function () {
    fakeBackend([
        'POST agents/*/clone' => Http::sequence()
            ->push(contract('agents.clone.201'), 201)
            ->push(contract('error'), 500),
        'DELETE agents/*' => Http::failedConnection(),
    ]);

    demoLogin()->assertSessionHasErrors('email');

    $leftover = sandboxes()->sole();

    // Resta solo l'agente copiato davvero: è quello da cancellare al prossimo giro.
    expect($leftover->demoSandboxExpired())->toBeTrue()
        ->and($leftover->agents()->pluck('backend_id')->all())->toBe([array_key_first(clonesSent())]);
});

test('una password demo sbagliata non apre niente', function () {
    demoLogin('password-sbagliata')->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();

    expect(User::count())->toBe(1);

    Http::assertNothingSent();
});

test('una sandbox non si apre con la sua email', function () {
    $sandbox = User::factory()->demoSandbox()->create();

    $this->post(route('login.store'), [
        'email' => $sandbox->email,
        'password' => config('stapply.demo.password'),
    ])->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

test('gli altri utenti entrano ed escono come prima', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'sbagliata'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();

    $this->post(route('login.store'), ['email' => strtoupper($user->email), 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'));

    expect($user->fresh()->demo_expires_at)->toBeNull()
        ->and(sandboxes())->toBeEmpty();

    Http::assertNothingSent();
});

test('oltre il tetto di sandbox aperte il login demo chiede di riprovare più tardi', function () {
    Config::set('stapply.demo.max_sandboxes', 2);
    User::factory()->demoSandbox()->count(2)->create();

    demoLogin()->assertSessionHasErrors(['email' => DemoUnavailableException::full()->getMessage()]);

    $this->assertGuest();

    expect(sandboxes())->toHaveCount(2);

    Http::assertNothingSent();
});

test('le sandbox scadute non contano nel tetto', function () {
    Config::set('stapply.demo.max_sandboxes', 1);
    User::factory()->expiredDemoSandbox()->create();
    fakeBackend(cloneOk());

    demoLogin()->assertRedirect(route('dashboard', absolute: false));
});

test('il login demo cancella qualche sandbox scaduta, dalla più vecchia', function () {
    Config::set('stapply.demo.prune_on_login', 1);

    $oldest = User::factory()->expiredDemoSandbox()->create(['demo_expires_at' => now()->subHour()]);
    $newer = User::factory()->expiredDemoSandbox()->create();
    $oldestAgent = Agent::factory()->for($oldest)->create();
    Agent::factory()->for($newer)->create();

    fakeBackend([...cloneOk(), 'DELETE agents/*' => Http::response(status: 204)]);

    demoLogin()->assertRedirect(route('dashboard', absolute: false));

    expect(agentsDeleted())->toBe([$oldestAgent->backend_id])
        ->and($oldest->fresh())->toBeNull()
        ->and($newer->fresh())->not->toBeNull();
});

test('la pulizia al login non fa mai fallire il login', function () {
    $expired = User::factory()->expiredDemoSandbox()->create();
    Agent::factory()->for($expired)->create();

    fakeBackend([...cloneOk(), 'DELETE agents/*' => Http::failedConnection()]);

    demoLogin()->assertRedirect(route('dashboard', absolute: false));

    expect($expired->fresh())->not->toBeNull()
        ->and(auth()->user()->isDemoSandbox())->toBeTrue();
});
