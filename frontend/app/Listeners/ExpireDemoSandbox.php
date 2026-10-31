<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Logout;

/**
 * Chi esce dalla demo non ci rientra: il prossimo accesso apre una sandbox nuova,
 * quindi questa si chiude subito e il prossimo prune la cancella.
 */
class ExpireDemoSandbox
{
    public function handle(Logout $event): void
    {
        if ($event->user instanceof User) {
            $event->user->expireDemoSandbox();
        }
    }
}
