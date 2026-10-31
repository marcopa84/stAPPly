<?php

namespace App\Services\Demo;

use App\Models\Agent;
use App\Models\User;
use App\Services\Backend\BackendClient;
use App\Services\Backend\BackendException;
use App\Support\DemoAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Le sandbox della demo. Ogni accesso con le credenziali demo crea un utente
 * temporaneo con la sua copia degli agenti demo del template: qui agenti nuovi con
 * le stesse istruzioni, nel backend documenti e tabelle copiati (POST /clone, senza
 * embedding nuovi). Il visitatore cambia quello che vuole senza toccare il template
 * né le sandbox degli altri.
 *
 * Una sandbox scade dopo stapply.demo.sandbox_minutes o al logout; prune() la
 * cancella, prima nel backend e poi qui, e non tocca mai gli agenti del template.
 */
class DemoSandbox
{
    public function __construct(private readonly BackendClient $backend) {}

    /**
     * Crea una sandbox con la copia di ogni agente demo del template. Se una copia
     * fallisce si annulla tutto quello creato in questo tentativo, e solo quello.
     *
     * @throws DemoUnavailableException
     */
    public function open(User $template): User
    {
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

        $copied = [];

        try {
            foreach ($template->agents()->where('is_demo', true)->oldest('id')->get() as $source) {
                // La copia è del visitatore: si modifica e si elimina come ogni suo agente.
                $copy = $sandbox->agents()->create([
                    'name' => $source->name,
                    'description' => $source->description,
                    'instructions' => $source->instructions,
                ]);

                $this->backend->cloneAgent($copy->backend_id, $source->backend_id);

                $copied[] = $copy->id;
            }
        } catch (Throwable $e) {
            $this->abandon($sandbox, $copied);

            throw $e instanceof BackendException ? DemoUnavailableException::backend($e) : $e;
        }

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
     * Annulla una sandbox appena creata. Gli agenti la cui copia non è riuscita non hanno
     * dati nel backend e si tolgono solo qui; gli altri li cancella purge(). Se il backend
     * non risponde nemmeno adesso la sandbox resta, scaduta, e la cancella il prossimo
     * prune: così nel backend non restano dati senza proprietario.
     *
     * @param  list<int>  $copied  gli agenti già copiati nel backend
     */
    private function abandon(User $sandbox, array $copied): void
    {
        $sandbox->agents()->whereKeyNot($copied)->delete();
        $sandbox->expireDemoSandbox();

        $this->purge($sandbox);
    }

    /**
     * Cancella una sandbox: i dati di ogni agente nel backend, poi l'utente con agenti,
     * chat e messaggi. Se il backend fallisce la sandbox resta per il giro dopo.
     */
    private function purge(User $sandbox): bool
    {
        foreach ($sandbox->agents()->get() as $agent) {
            if ($this->isProtected($agent)) {
                Log::error('Demo: rifiutata la cancellazione di un agente demo o del template; la sandbox resta.', [
                    'sandbox' => $sandbox->id,
                    'backend_id' => $agent->backend_id,
                ]);

                return false;
            }

            try {
                $this->backend->deleteAgent($agent->backend_id);
            } catch (BackendException $e) {
                Log::warning('Demo: il backend non ha cancellato un agente della sandbox, si riprova al prossimo giro.', [
                    'sandbox' => $sandbox->id,
                    'backend_id' => $agent->backend_id,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }

            // Già cancellato nel backend: al prossimo giro non si ripete.
            $agent->delete();
        }

        $sandbox->delete();

        return true;
    }

    /**
     * Ultima difesa prima di una DELETE: un agente demo o del template non si cancella
     * mai, qualunque errore ci sia nel resto del codice. Si controlla sul DB, non su un
     * elenco di id scritto a mano.
     */
    private function isProtected(Agent $agent): bool
    {
        return Agent::query()
            ->where('backend_id', $agent->backend_id)
            ->where(fn (Builder $query) => $query
                ->where('is_demo', true)
                ->orWhereHas('user', fn (Builder $owner) => $owner
                    ->where('email', Str::lower(Config::string('stapply.demo.email')))
                    ->whereNull('demo_expires_at')))
            ->exists();
    }
}
