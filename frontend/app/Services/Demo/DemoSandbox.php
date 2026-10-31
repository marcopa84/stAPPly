<?php

namespace App\Services\Demo;

use App\Models\User;
use App\Services\Agents\AgentPurger;
use App\Services\Agents\ProtectedAgentException;
use App\Services\Backend\BackendClient;
use App\Services\Backend\BackendException;
use App\Support\DemoAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Le sandbox della demo. Ogni accesso con le credenziali demo crea un utente
 * temporaneo con la sua copia degli agenti demo del template: qui agenti nuovi con
 * le stesse istruzioni, nel backend documenti e tabelle copiati (POST /clone, senza
 * embedding nuovi). Il visitatore cambia quello che vuole senza toccare il template
 * né le sandbox degli altri.
 *
 * Una sandbox scade dopo stapply.demo.sandbox_minutes o al logout; prune() la
 * cancella, prima nel backend e poi qui, e non tocca mai gli agenti del template
 * (AgentPurger).
 */
class DemoSandbox
{
    public function __construct(
        private readonly BackendClient $backend,
        private readonly AgentPurger $agents,
    ) {}

    /**
     * Crea una sandbox con la copia di ogni agente demo del template. Se una copia
     * fallisce si annulla tutto quello creato in questo tentativo, e solo quello.
     *
     * Le sandbox aperte sono al massimo stapply.demo.max_sandboxes, e uno stesso IP ne
     * apre al massimo stapply.demo.sandboxes_per_ip_per_hour all'ora: senza, una sola
     * persona, scartando il cookie a ogni login, riempirebbe la demo per tutti.
     *
     * @throws DemoUnavailableException
     */
    public function open(User $template, string $ip): User
    {
        $perIp = "demo-sandboxes:ip:{$ip}";

        if (RateLimiter::tooManyAttempts($perIp, Config::integer('stapply.demo.sandboxes_per_ip_per_hour'))) {
            throw DemoUnavailableException::tooManyFromHere(RateLimiter::availableIn($perIp));
        }

        $this->pruneQuietly();

        if (User::query()->activeDemoSandbox()->count() >= Config::integer('stapply.demo.max_sandboxes')) {
            throw DemoUnavailableException::full();
        }

        $sandbox = new User;
        $sandbox->forceFill([
            'name' => 'Visitatore demo',
            'email' => DemoAccount::newSandboxEmail(),
            // Lo stesso hash del template: la conferma della password nelle impostazioni
            // accetta la password pubblica, e il visitatore vede che lì è tutto bloccato.
            'password' => $template->password,
            'email_verified_at' => now(),
            'demo_expires_at' => now()->addMinutes(Config::integer('stapply.demo.sandbox_minutes')),
        ])->save();

        try {
            foreach ($template->agents()->where('is_demo', true)->oldest('id')->get() as $source) {
                // La copia è del visitatore: si modifica e si elimina come ogni suo agente.
                $copy = $sandbox->agents()->create([
                    'name' => $source->name,
                    'description' => $source->description,
                    'instructions' => $source->instructions,
                ]);

                $this->backend->cloneAgent($copy->backend_id, $source->backend_id);
            }
        } catch (Throwable $e) {
            $this->abandon($sandbox);

            throw $e instanceof BackendException ? DemoUnavailableException::backend($e) : $e;
        }

        // Contano solo le sandbox aperte davvero: un guasto del backend non toglie tentativi.
        RateLimiter::hit($perIp, 3600);

        return $sandbox;
    }

    /**
     * Cancella le sandbox scadute, dalla più vecchia.
     *
     * @return int quante ne ha cancellate: le altre si riprovano al giro dopo
     */
    public function prune(?int $limit = null): int
    {
        $expired = User::query()
            ->expiredDemoSandbox()
            ->oldest('demo_expires_at')
            ->when($limit !== null, fn (Builder $query) => $query->limit((int) $limit))
            ->get();

        $pruned = 0;

        foreach ($expired as $sandbox) {
            if ($this->purge($sandbox)) {
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * Herd non ha cron: anche ogni login demo cancella qualche sandbox scaduta. Non deve
     * mai far fallire il login, quindi un errore finisce solo nel log.
     */
    private function pruneQuietly(): void
    {
        try {
            $this->prune(Config::integer('stapply.demo.prune_on_login'));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Annulla una sandbox appena creata: la chiude, e purge() manda la DELETE per ogni
     * suo agente, anche per quello la cui copia è fallita. Un errore visto da qui (un
     * timeout, una risposta inattesa) non dice che il backend non abbia copiato niente,
     * e la DELETE va bene anche per un agente senza dati. Se il backend non risponde
     * nemmeno adesso la sandbox resta, scaduta, e la cancella il prossimo prune: così
     * nel backend non restano dati senza proprietario.
     */
    private function abandon(User $sandbox): void
    {
        $sandbox->expireDemoSandbox();

        $this->purge($sandbox);
    }

    /**
     * Cancella una sandbox: ogni agente, prima nel backend e poi qui, poi l'utente con
     * chat e messaggi. Se il backend fallisce la sandbox resta per il giro dopo, senza
     * gli agenti già cancellati.
     */
    private function purge(User $sandbox): bool
    {
        foreach ($sandbox->agents()->get() as $agent) {
            try {
                $this->agents->purge($agent);
            } catch (ProtectedAgentException) {
                Log::error('Demo: rifiutata la cancellazione di un agente demo o del template; la sandbox resta.', [
                    'sandbox' => $sandbox->id,
                    'backend_id' => $agent->backend_id,
                ]);

                return false;
            } catch (BackendException $e) {
                Log::warning('Demo: il backend non ha cancellato un agente della sandbox, si riprova al prossimo giro.', [
                    'sandbox' => $sandbox->id,
                    'backend_id' => $agent->backend_id,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        $sandbox->delete();

        return true;
    }
}
