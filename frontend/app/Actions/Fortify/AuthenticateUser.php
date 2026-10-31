<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Demo\DemoSandbox;
use App\Services\Demo\DemoUnavailableException;
use App\Support\DemoAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Il controllo delle credenziali al login (Fortify::authenticateUsing).
 *
 * Per gli utenti registrati è quello standard di Fortify: utente cercato per email e
 * password verificata, in un tempo minimo fisso perché la durata non riveli se
 * l'email esiste. Le credenziali demo invece non fanno entrare nel template: aprono
 * una sandbox nuova, con la sua copia degli agenti demo. Le sandbox non si aprono
 * con la propria email: ogni accesso demo riparte dai valori iniziali.
 *
 * Fortify chiama questa callback due volte per lo stesso login (in
 * RedirectIfTwoFactorAuthenticatable e in AttemptToAuthenticate): il risultato si
 * conserva sulla richiesta, così un login demo apre una sola sandbox.
 */
class AuthenticateUser
{
    private const RESULT = 'stapply.authenticated_user';

    /** Lo stesso tempo minimo che Fortify usa senza callback, in microsecondi. */
    private const MINIMUM_MICROSECONDS = 200_000;

    public function __construct(private readonly DemoSandbox $sandboxes) {}

    /**
     * @throws ValidationException se le credenziali demo sono giuste ma la sandbox non si apre
     */
    public function __invoke(Request $request): ?User
    {
        if (! $request->attributes->has(self::RESULT)) {
            $request->attributes->set(self::RESULT, $this->authenticate($request));
        }

        $user = $request->attributes->get(self::RESULT);

        return $user instanceof User ? $user : null;
    }

    private function authenticate(Request $request): ?User
    {
        $user = (new Timebox)->call(
            fn () => $this->userWithCredentials($request->string(Fortify::username())->value(), $request->string('password')->value()),
            self::MINIMUM_MICROSECONDS,
        );

        if ($user === null || $user->isDemoSandbox()) {
            return null;
        }

        return DemoAccount::isTemplate($user) ? $this->openSandbox($user) : $user;
    }

    private function userWithCredentials(string $email, string $password): ?User
    {
        $user = User::query()->where('email', $email)->first();

        return $user !== null && Hash::check($password, $user->password) ? $user : null;
    }

    /**
     * Un errore sotto l'email e non un "credenziali sbagliate": le credenziali sono
     * giuste, e così il limite di tentativi di Fortify non conta un guasto del backend.
     */
    private function openSandbox(User $template): User
    {
        try {
            return $this->sandboxes->open($template);
        } catch (DemoUnavailableException $e) {
            throw ValidationException::withMessages([Fortify::username() => $e->getMessage()]);
        }
    }
}
