<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\DemoAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chiude la sessione di una sandbox scaduta, e quella del template, che dopo
 * l'arrivo delle sandbox non si usa più direttamente (per esempio una sessione
 * aperta prima). Il visitatore torna alla home, che spiega come ripartire.
 *
 * Il messaggio passa nell'URL e non in sessione: dopo un'azione Livewire o un
 * wire:navigate il browser segue il redirect con fetch, che consumerebbe il flash
 * prima che la pagina arrivi davvero.
 */
class EndFinishedDemoSession
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! DemoAccount::isTemplate($user) && ! ($user instanceof User && $user->demoSandboxExpired())) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home', ['demo' => 'terminata']);
    }
}
