<?php

namespace App\Http\Middleware;

use App\Support\DemoAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le rotte di Fortify e delle passkey cambiano le credenziali senza passare dai
 * componenti Livewire delle impostazioni: per gli utenti della demo (template e
 * sandbox) vanno chiuse qui. La conferma della password che le precede non
 * protegge niente, perché quella password è pubblica.
 */
class ProtectDemoAccount
{
    /** Rotte che cambiano doppia autenticazione e passkey dell'utente autenticato. */
    private const ACCOUNT_ROUTES = [
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.regenerate-recovery-codes',
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];

    /** Rotte del recupero password: lì l'utente si riconosce dall'email inviata. */
    private const RESET_ROUTES = ['password.email', 'password.update'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::ACCOUNT_ROUTES) && DemoAccount::is($request->user())) {
            abort(403, DemoAccount::MESSAGE);
        }

        if ($request->routeIs(...self::RESET_ROUTES) && DemoAccount::isEmail($request->input('email'))) {
            throw ValidationException::withMessages(['email' => DemoAccount::RESET_MESSAGE]);
        }

        return $next($request);
    }
}
