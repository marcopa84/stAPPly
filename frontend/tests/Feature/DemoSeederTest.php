<?php

use App\Models\Agent;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

test("crea l'utente demo verificato con i due agenti demo", function () {
    $this->seed(DemoSeeder::class);

    $user = User::sole();

    expect($user->email)->toBe('demo@stapply.test')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check(config('stapply.demo.password'), $user->password))->toBeTrue()
        ->and($user->agents()->pluck('backend_id')->sort()->values()->all())->toBe(['demo-valdoria', 'demo-valtesa'])
        ->and($user->agents()->where('is_demo', false)->exists())->toBeFalse();

    expect(Agent::where('backend_id', 'demo-valtesa')->sole())
        ->name->toBe('Assistente Valtesa')
        ->instructions->toStartWith("Sei l'assistente dell'ufficio del personale di Valtesa S.r.l.")
        ->instructions->toContain('usa cerca_documenti', 'usa query_db');
});

test('si può rilanciare senza duplicare e riporta gli agenti demo allo stato iniziale', function () {
    $this->seed(DatabaseSeeder::class);

    Agent::where('backend_id', 'demo-valdoria')->update(['instructions' => 'Modificate a mano.']);

    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(1)
        ->and(Agent::count())->toBe(2)
        ->and(Agent::where('backend_id', 'demo-valdoria')->sole()->instructions)->toBe(DemoSeeder::VALDORIA_INSTRUCTIONS);
});

test('la password della configurazione apre una sandbox con la copia degli agenti seminati', function () {
    $this->seed(DemoSeeder::class);
    fakeBackend(['POST agents/*/clone' => Http::response(contract('agents.clone.201'), 201)]);

    $this->post(route('login.store'), [
        'email' => 'demo@stapply.test',
        'password' => config('stapply.demo.password'),
    ])->assertRedirect(route('dashboard', absolute: false));

    $sandbox = User::whereNotNull('demo_expires_at')->sole();

    $this->assertAuthenticatedAs($sandbox);

    expect($sandbox->agents()->pluck('name')->sort()->values()->all())->toBe(['Assistente Valtesa', 'Biblioteca di Valdoria']);
});
