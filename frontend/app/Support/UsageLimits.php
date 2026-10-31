<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/**
 * I limiti che tengono basso il costo della chiave OpenAI del progetto: ogni
 * caricamento paga gli embedding, ogni messaggio una o più chiamate al modello.
 *
 * Valgono per ogni utente (config stapply): agenti, caricamenti all'ora e al giorno,
 * messaggi di chat al minuto e al giorno. Le sandbox della demo, che chiunque può
 * aprire, hanno valori più bassi (stapply.demo) e due limiti comuni a tutte le
 * sandbox insieme, con una chiave unica nel RateLimiter: messaggi al minuto e
 * caricamenti all'ora.
 */
final class UsageLimits
{
    private const DEMO_CHAT_KEY = 'demo-sandboxes:chat-messages';

    private const DEMO_UPLOADS_KEY = 'demo-sandboxes:uploads';

    public static function maxAgents(User $user): int
    {
        return Config::integer(DemoAccount::isSandbox($user) ? 'stapply.demo.max_agents' : 'stapply.max_agents');
    }

    /**
     * Il motivo per cui l'utente non può creare un altro agente, o null se può.
     */
    public static function agentLimitReason(User $user): ?string
    {
        $max = self::maxAgents($user);

        if ($user->agents()->count() < $max) {
            return null;
        }

        return (DemoAccount::isSandbox($user) ? 'Nella demo puoi' : 'Puoi')
            ." avere al massimo {$max} agenti: eliminane uno per crearne un altro.";
    }

    public static function maxUploadMb(User $user): int
    {
        return Config::integer(DemoAccount::isSandbox($user) ? 'stapply.demo.max_upload_mb' : 'stapply.max_upload_mb');
    }

    public static function maxDocuments(User $user): int
    {
        return Config::integer(DemoAccount::isSandbox($user) ? 'stapply.demo.max_documents' : 'stapply.max_documents');
    }

    public static function maxTables(User $user): int
    {
        return Config::integer(DemoAccount::isSandbox($user) ? 'stapply.demo.max_tables' : 'stapply.max_tables');
    }

    /**
     * Conta un messaggio di chat nei limiti dell'utente e, per le sandbox, in quello
     * comune a tutte. Se un limite è pieno non conta niente e restituisce il motivo.
     */
    public static function hitChatMessage(User $user): ?string
    {
        $limits = [
            [
                Limit::perMinute(Config::integer('stapply.chat_messages_per_minute'))->by("chat-messages:{$user->id}"),
                'Hai mandato troppi messaggi in poco tempo: riprova tra :attesa.',
            ],
            [
                Limit::perDay(Config::integer('stapply.chat_messages_per_day'))->by("chat-messages-day:{$user->id}"),
                'Hai mandato tutti i messaggi a disposizione per oggi: riprova tra :attesa.',
            ],
        ];

        if (DemoAccount::isSandbox($user)) {
            $limits[] = [
                Limit::perMinute(Config::integer('stapply.demo.chat_messages_per_minute'))->by(self::DEMO_CHAT_KEY),
                'In questo momento la demo riceve troppi messaggi: riprova tra :attesa.',
            ];
        }

        return self::attempt($limits);
    }

    /**
     * Conta un caricamento nei limiti dell'utente e, per le sandbox, in quello orario
     * comune a tutte. Se un limite è pieno non conta niente e restituisce il motivo.
     */
    public static function hitUpload(User $user): ?string
    {
        $limits = [
            [
                Limit::perHour(Config::integer('stapply.uploads_per_hour'))->by("uploads:{$user->id}"),
                'Hai caricato troppi file nell\'ultima ora: riprova tra :attesa.',
            ],
            [
                Limit::perDay(Config::integer('stapply.uploads_per_day'))->by("uploads-day:{$user->id}"),
                'Hai caricato tutti i file a disposizione per oggi: riprova tra :attesa.',
            ],
        ];

        if (DemoAccount::isSandbox($user)) {
            $limits[] = [
                Limit::perHour(Config::integer('stapply.demo.uploads_per_hour'))->by(self::DEMO_UPLOADS_KEY),
                'La demo ha raggiunto il limite di caricamenti per quest\'ora: riprova tra :attesa.',
            ];
        }

        return self::attempt($limits);
    }

    /**
     * Conta un tentativo in ogni limite, ma solo se nessuno è già pieno: così un limite
     * pieno non consuma gli altri. Altrimenti restituisce il motivo del primo pieno.
     *
     * @param  list<array{0: Limit, 1: string}>  $limits  il limite e il motivo da mostrare, con :attesa
     */
    private static function attempt(array $limits): ?string
    {
        foreach ($limits as [$limit, $reason]) {
            if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
                return str_replace(':attesa', self::wait(RateLimiter::availableIn($limit->key)), $reason);
            }
        }

        foreach ($limits as [$limit]) {
            RateLimiter::hit($limit->key, $limit->decaySeconds);
        }

        return null;
    }

    private static function wait(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? 'un secondo' : "{$seconds} secondi";
        }

        if ($seconds <= 3600) {
            $minutes = (int) ceil($seconds / 60);

            return $minutes === 1 ? 'un minuto' : "{$minutes} minuti";
        }

        $hours = (int) ceil($seconds / 3600);

        return "{$hours} ore";
    }
}
