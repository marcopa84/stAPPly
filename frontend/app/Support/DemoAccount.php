<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Gli utenti della demo sono di due tipi. Il template è l'utente demo di DemoSeeder,
 * con gli agenti demo: le sue credenziali sono pubbliche, ma nessuno lo usa
 * direttamente. Le sandbox sono gli utenti temporanei che ogni accesso con quelle
 * credenziali crea, con la propria copia degli agenti (App\Services\Demo\DemoSandbox).
 *
 * Per entrambi credenziali e account sono bloccati: password, email, doppia
 * autenticazione e passkey cambiate da un visitatore lascerebbero fuori gli altri
 * (template) o aprirebbero un accesso che non scade (sandbox).
 */
final class DemoAccount
{
    public const MESSAGE = 'Nella demo nome, email, password, autenticazione a due fattori e passkey non si possono modificare, e l\'account non si elimina.';

    public const RESET_MESSAGE = 'La password della demo non si può reimpostare.';

    public const ENDED_MESSAGE = 'La tua demo è terminata. Con «Accedi alla demo» ne apri una nuova, che riparte dai valori iniziali.';

    /** In sessione dal login demo: dice che la sessione era di una sandbox anche dopo che il prune l'ha cancellata. */
    public const SANDBOX_SESSION_KEY = 'demo_sandbox';

    /** Dominio delle email delle sandbox: .invalid è riservato, nessuna email può arrivarci. */
    public const SANDBOX_DOMAIN = 'sandbox.stapply.invalid';

    /**
     * Il template o una sandbox: valgono gli stessi blocchi.
     */
    public static function is(mixed $user): bool
    {
        return self::isTemplate($user) || self::isSandbox($user);
    }

    public static function isTemplate(mixed $user): bool
    {
        return $user instanceof User && ! $user->isDemoSandbox() && self::isTemplateEmail($user->email);
    }

    public static function isSandbox(mixed $user): bool
    {
        return $user instanceof User && $user->isDemoSandbox();
    }

    /**
     * L'email del template, quella pubblicata nella home.
     */
    public static function isTemplateEmail(mixed $email): bool
    {
        return is_string($email)
            && Str::lower(trim($email)) === Str::lower(Config::string('stapply.demo.email'));
    }

    /**
     * L'email del template o di una sandbox: per il recupero password, che riconosce
     * l'utente dall'email inviata.
     */
    public static function isEmail(mixed $email): bool
    {
        return self::isTemplateEmail($email)
            || (is_string($email) && Str::endsWith(Str::lower(trim($email)), '@'.self::SANDBOX_DOMAIN));
    }

    public static function template(): ?User
    {
        return User::query()
            ->where('email', Str::lower(Config::string('stapply.demo.email')))
            ->whereNull('demo_expires_at')
            ->first();
    }

    /**
     * Un'email nuova per una sandbox: unica e impossibile da indovinare, e in minuscolo
     * come le salva Fortify (lowercase_usernames).
     */
    public static function newSandboxEmail(): string
    {
        return 'demo-'.Str::lower((string) Str::ulid()).'@'.self::SANDBOX_DOMAIN;
    }

    /**
     * Per le azioni Livewire, che non passano dal middleware delle rotte.
     */
    public static function abortIfDemo(mixed $user): void
    {
        abort_if(self::is($user), 403, self::MESSAGE);
    }
}
