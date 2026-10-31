<?php

it('mostra la pagina di accesso in italiano', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<html lang="it"', false)
        ->assertSee('Accedi al tuo account')
        ->assertSee('Password dimenticata?')
        ->assertSee('Ricordami')
        ->assertDontSee('Log in to your account');
});

it('traduce gli errori di validazione e i nomi dei campi', function () {
    $this->from(route('login'))
        ->post(route('login.store'), ['email' => '', 'password' => ''])
        ->assertSessionHasErrors([
            'email' => 'Il campo email è obbligatorio.',
            'password' => 'Il campo password è obbligatorio.',
        ]);
});

it('traduce il messaggio di credenziali errate', function () {
    $this->from(route('login'))
        ->post(route('login.store'), ['email' => 'nessuno@stapply.test', 'password' => 'sbagliata'])
        ->assertSessionHasErrors(['email' => 'Queste credenziali non corrispondono ai nostri dati.']);
});
