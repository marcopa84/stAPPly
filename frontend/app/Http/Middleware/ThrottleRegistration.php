<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify registra la rotta della registrazione senza limite di tentativi. Senza,
 * uno script prova migliaia di email e dall'errore "già in uso" ricava quali sono
 * registrate (il recupero password invece non lo dice), o crea account in serie,
 * ognuno con la sua email di verifica. Qui la rotta passa dal limiter "register"
 * di FortifyServiceProvider.
 */
class ThrottleRegistration
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('register.store')) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, 'register');
    }
}
