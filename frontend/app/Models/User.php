<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $demo_expires_at solo per le sandbox della demo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Agent> $agents
 * @property-read Collection<int, Conversation> $conversations
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'demo_expires_at' => 'datetime',
        ];
    }

    /**
     * Una sandbox della demo: un utente temporaneo, creato a ogni accesso con le
     * credenziali demo, con la sua copia degli agenti demo (vedi App\Services\Demo\DemoSandbox).
     */
    public function isDemoSandbox(): bool
    {
        return $this->demo_expires_at !== null;
    }

    /**
     * Scaduta dal momento indicato in poi, come nello scope expiredDemoSandbox.
     */
    public function demoSandboxExpired(): bool
    {
        return $this->demo_expires_at !== null && ! $this->demo_expires_at->isFuture();
    }

    /**
     * Chiude la sandbox: non si usa più e il prossimo prune la cancella.
     */
    public function expireDemoSandbox(): void
    {
        if ($this->isDemoSandbox() && ! $this->demoSandboxExpired()) {
            $this->forceFill(['demo_expires_at' => now()])->save();
        }
    }

    /**
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function activeDemoSandbox(Builder $query): void
    {
        $query->where('demo_expires_at', '>', now());
    }

    /**
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function expiredDemoSandbox(Builder $query): void
    {
        $query->where('demo_expires_at', '<=', now());
    }

    /**
     * @return HasMany<Agent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
