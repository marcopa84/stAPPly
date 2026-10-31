<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/**
 * I limiti delle sandbox demo (config stapply.demo): ogni sandbox spende la chiave
 * OpenAI del progetto, e chiunque può aprirne una. Ci sono limiti per sandbox
 * (agenti, file per agente, dimensione dei file) e limiti comuni a tutte le sandbox
 * insieme (messaggi al minuto, caricamenti all'ora), con una chiave unica nel
 * RateLimiter. Per gli altri utenti valgono i limiti generali.
 */
final class DemoLimits
{
    private const CHAT_KEY = 'demo-sandboxes:chat-messages';

    private const UPLOADS_KEY = 'demo-sandboxes:uploads';

    public static function maxAgents(): int
    {
        return Config::integer('stapply.demo.max_agents');
    }

    /**
     * Il motivo per cui l'utente non può creare un altro agente, o null se può.
     */
    public static function agentLimitReason(User $user): ?string
    {
        if (! DemoAccount::isSandbox($user) || $user->agents()->count() < self::maxAgents()) {
            return null;
        }

        return 'Nella demo puoi avere al massimo '.self::maxAgents().' agenti: eliminane uno per crearne un altro.';
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
     * Conta un messaggio di chat di una sandbox nel limite comune a tutte. Se il limite
     * è pieno non conta niente e restituisce il motivo da mostrare.
     */
    public static function hitChatMessage(): ?string
    {
        $seconds = self::hit(self::CHAT_KEY, Config::integer('stapply.demo.chat_messages_per_minute'), 60);

        return $seconds === null ? null : 'In questo momento la demo riceve troppi messaggi: riprova tra '.self::wait($seconds).'.';
    }

    /**
     * Conta un caricamento di una sandbox nel limite orario comune a tutte.
     */
    public static function hitUpload(): ?string
    {
        $seconds = self::hit(self::UPLOADS_KEY, Config::integer('stapply.demo.uploads_per_hour'), 3600);

        return $seconds === null ? null : 'La demo ha raggiunto il limite di caricamenti per quest\'ora: riprova tra '.self::wait($seconds).'.';
    }

    /**
     * @return int|null i secondi da aspettare se il limite è pieno; altrimenti null, e il tentativo è contato
     */
    private static function hit(string $key, int $max, int $decaySeconds): ?int
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return RateLimiter::availableIn($key);
        }

        RateLimiter::hit($key, $decaySeconds);

        return null;
    }

    private static function wait(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? 'un secondo' : "{$seconds} secondi";
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes === 1 ? 'un minuto' : "{$minutes} minuti";
    }
}
