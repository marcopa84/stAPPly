<?php

use App\Livewire\Settings\DeleteUserForm;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;
use App\Livewire\Settings\TwoFactor\RecoveryCodes;
use App\Models\Agent;
use App\Models\User;
use App\Support\DemoAccount;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DemoSeeder::class);
    $this->demo = DemoAccount::template();
});

/*
 * I blocchi valgono per il template, che nessuno usa più direttamente ma resta la
 * fonte di ogni sandbox, e per le sandbox, che i visitatori usano davvero. Le rotte
 * HTTP si provano solo con la sandbox: una sessione sul template si chiude prima
 * (EndFinishedDemoSession, in Demo/DemoSandboxLifecycleTest).
 */
dataset('utenti della demo', [
    'template' => fn () => DemoAccount::template(),
    'sandbox' => fn () => User::factory()->demoSandbox()->create(),
]);

test('il pulsante della home porta al login con le credenziali demo già inserite', function () {
    $this->get('/')->assertSee(route('login', ['demo' => 1]), false);

    $this->get(route('login', ['demo' => 1]))
        ->assertOk()
        ->assertSee("Credenziali dell'utente demo già inserite")
        ->assertSee('value="'.config('stapply.demo.email').'"', false)
        ->assertSee('value="'.config('stapply.demo.password').'"', false);
});

test('senza il parametro demo il login resta vuoto', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('value="'.config('stapply.demo.email').'"', false)
        ->assertDontSee('value="'.config('stapply.demo.password').'"', false);
});

test('la demo non può cambiare la password', function (User $user) {
    Livewire::actingAs($user)
        ->test(Security::class)
        ->set('current_password', config('stapply.demo.password'))
        ->set('password', 'una-password-nuova-e-lunga')
        ->set('password_confirmation', 'una-password-nuova-e-lunga')
        ->call('updatePassword')
        ->assertForbidden();

    expect(Hash::check(config('stapply.demo.password'), $user->fresh()->password))->toBeTrue();
})->with('utenti della demo');

test('la demo non può toccare doppia autenticazione e passkey dalle impostazioni', function (string $method, User $user) {
    Livewire::actingAs($user)
        ->test(Security::class)
        ->set('code', '123456')
        ->call($method)
        ->assertForbidden();

    expect($user->fresh()->two_factor_secret)->toBeNull();
})->with(['enable', 'confirmTwoFactor', 'disable', 'deletePasskey'])->with('utenti della demo');

test('la demo non può rigenerare i codici di recupero', function (User $user) {
    Livewire::actingAs($user)
        ->test(RecoveryCodes::class)
        ->call('regenerateRecoveryCodes')
        ->assertForbidden();
})->with('utenti della demo');

test('la demo non può cambiare nome ed email', function (User $user) {
    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('name', 'Qualcun altro')
        ->set('email', 'altro@example.com')
        ->call('updateProfileInformation')
        ->assertForbidden();

    expect($user->fresh())
        ->name->toBe($user->name)
        ->email->toBe($user->email);
})->with('utenti della demo');

test("la demo non può eliminare l'account", function (User $user) {
    Agent::factory()->for($user)->create();
    $agents = $user->agents()->count();

    Livewire::actingAs($user)
        ->test(DeleteUserForm::class)
        ->set('password', config('stapply.demo.password'))
        ->call('deleteUser')
        ->assertForbidden();

    expect($user->fresh())->not->toBeNull()
        ->and($user->agents()->count())->toBe($agents);
})->with('utenti della demo');

test('le rotte di Fortify e delle passkey sono chiuse alle sandbox', function (string $method, string $route) {
    $sandbox = User::factory()->demoSandbox()->create();

    // Una passkey vera, perché passkey.destroy risolve il modello prima del middleware.
    $passkey = $sandbox->passkeys()->create([
        'name' => 'Chiave di prova',
        'credential_id' => 'credenziale-di-prova',
        'credential' => [],
    ]);

    $this->actingAs($sandbox)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->call($method, route($route, $route === 'passkey.destroy' ? ['passkey' => $passkey->id] : []))
        ->assertForbidden();

    expect($sandbox->fresh()->two_factor_secret)->toBeNull()
        ->and($sandbox->passkeys()->count())->toBe(1);
})->with([
    ['POST', 'two-factor.enable'],
    ['POST', 'two-factor.confirm'],
    ['DELETE', 'two-factor.disable'],
    ['POST', 'two-factor.regenerate-recovery-codes'],
    ['GET', 'passkey.registration-options'],
    ['POST', 'passkey.store'],
    ['DELETE', 'passkey.destroy'],
]);

test('gli altri utenti attivano la doppia autenticazione come prima', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertRedirect();

    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

test('il recupero password non parte per la demo', function (string $email) {
    Notification::fake();

    $this->post(route('password.email'), ['email' => $email])
        ->assertSessionHasErrors(['email' => DemoAccount::RESET_MESSAGE]);

    Notification::assertNothingSent();
})->with([
    'template' => 'demo@stapply.test',
    'template con spazi e maiuscole' => ' DEMO@stapply.test ',
    'sandbox' => fn () => User::factory()->demoSandbox()->create()->email,
]);

test('il reset con token non cambia la password della demo', function (User $user) {
    $this->post(route('password.update'), [
        'token' => 'un-token-qualsiasi',
        'email' => $user->email,
        'password' => 'una-password-nuova-e-lunga',
        'password_confirmation' => 'una-password-nuova-e-lunga',
    ])->assertSessionHasErrors(['email' => DemoAccount::RESET_MESSAGE]);

    expect(Hash::check(config('stapply.demo.password'), $user->fresh()->password))->toBeTrue();
})->with('utenti della demo');

test('le impostazioni della sandbox spiegano che sono bloccate', function () {
    $sandbox = User::factory()->demoSandbox()->create();

    $this->actingAs($sandbox)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee(DemoAccount::MESSAGE)
        ->assertDontSee('wire:submit="updateProfileInformation"', false);

    $this->actingAs($sandbox)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee(DemoAccount::MESSAGE)
        ->assertDontSee('wire:submit="updatePassword"', false);
});

test('la sandbox conferma la password con quella pubblica della demo e trova le impostazioni bloccate', function () {
    $sandbox = User::factory()->demoSandbox()->create();

    $this->actingAs($sandbox)
        ->post(route('password.confirm.store'), ['password' => config('stapply.demo.password')])
        ->assertSessionHasNoErrors();

    $this->get(route('security.edit'))
        ->assertOk()
        ->assertSee(DemoAccount::MESSAGE);
});

test('il seeder rimette a posto un account demo modificato', function () {
    $this->demo->forceFill([
        'name' => 'Qualcun altro',
        'password' => 'una-password-diversa',
        'two_factor_secret' => encrypt('segreto'),
        'two_factor_recovery_codes' => encrypt('[]'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->demo->passkeys()->create([
        'name' => 'Chiave di un visitatore',
        'credential_id' => 'credenziale-di-un-visitatore',
        'credential' => [],
    ]);

    $this->seed(DemoSeeder::class);

    expect($this->demo->fresh())
        ->name->toBe('Utente demo')
        ->two_factor_secret->toBeNull()
        ->two_factor_recovery_codes->toBeNull()
        ->two_factor_confirmed_at->toBeNull()
        ->and(Hash::check(config('stapply.demo.password'), $this->demo->fresh()->password))->toBeTrue()
        ->and($this->demo->passkeys()->count())->toBe(0);
});
