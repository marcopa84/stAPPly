<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    /**
     * L'errore "email già in uso" dice chi è registrato: senza limite si proverebbero
     * migliaia di indirizzi. Contano anche i tentativi rifiutati dalla validazione.
     */
    public function test_la_registrazione_ha_un_limite_di_tentativi_per_ip(): void
    {
        $attempt = fn (int $n) => $this->post(route('register.store'), [
            'name' => 'Mario Rossi',
            'email' => "mario{$n}@example.com",
            'password' => 'password',
            'password_confirmation' => 'non-coincide',
        ]);

        foreach (range(1, 5) as $n) {
            $attempt($n)->assertSessionHasErrors('password');
        }

        $attempt(6)->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);

        $attempt(7)->assertSessionHasErrors('password');
    }
}
