<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Demo\DemoSandbox;
use Illuminate\Console\Command;

/**
 * Pianificato ogni ora in routes/console.php. Una sandbox che il backend non riesce
 * a cancellare resta e si riprova al giro dopo: per questo il comando fallisce,
 * così lo scheduler lo segnala.
 */
class PruneDemoSandboxes extends Command
{
    protected $signature = 'stapply:prune-demo';

    protected $description = 'Cancella le sandbox demo scadute: prima i loro dati nel backend, poi utente, agenti e chat';

    public function handle(DemoSandbox $sandboxes): int
    {
        $pruned = $sandboxes->prune();
        $left = User::query()->expiredDemoSandbox()->count();

        $this->info("Sandbox scadute cancellate: {$pruned}.");

        if ($left > 0) {
            $this->warn("Sandbox da riprovare al prossimo giro: {$left} (dettagli nel log).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
