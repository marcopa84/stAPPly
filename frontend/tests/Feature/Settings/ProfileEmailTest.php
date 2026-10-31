<?php

use App\Livewire\Settings\Profile;
use App\Models\User;
use Livewire\Livewire;

test("l'email cambiata dal profilo si salva in minuscolo, e il login la ritrova scritta come si vuole", function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('name', $user->name)
        ->set('email', ' Mario.Rossi@Example.com ')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->email)->toBe('mario.rossi@example.com');

    $this->post(route('logout'));
    $this->app['auth']->forgetGuards();

    $this->post(route('login.store'), ['email' => 'Mario.Rossi@Example.com', 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test("un'email già usata non si prende cambiando le maiuscole", function (string $email) {
    User::factory()->create(['email' => 'mario.rossi@example.com']);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('name', $user->name)
        ->set('email', $email)
        ->call('updateProfileInformation')
        ->assertHasErrors(['email' => 'unique']);

    expect($user->fresh()->email)->not->toBe('mario.rossi@example.com');
})->with(['Mario.Rossi@Example.com', 'MARIO.ROSSI@EXAMPLE.COM']);
