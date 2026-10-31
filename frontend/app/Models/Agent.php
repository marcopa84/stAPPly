<?php

namespace App\Models;

use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Un assistente con istruzioni proprie. Documenti, tabelle e conversazioni stanno
 * nel backend, sotto il suo backend_id; qui restano proprietario e istruzioni.
 *
 * @property int $id
 * @property int $user_id
 * @property string $backend_id
 * @property string $name
 * @property string|null $description
 * @property string $instructions
 * @property bool $is_demo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Conversation> $conversations
 */
#[Fillable(['backend_id', 'name', 'description', 'instructions', 'is_demo'])]
#[RouteKey('backend_id')]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    /**
     * Niente HasUlids: rifiuterebbe nelle rotte gli id demo-valtesa e demo-valdoria,
     * che non sono ULID. Il ULID in minuscolo rispetta la regex di agent_id del backend.
     */
    protected static function booted(): void
    {
        static::creating(function (Agent $agent): void {
            if (blank($agent->backend_id)) {
                $agent->backend_id = Str::lower((string) Str::ulid());
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
        ];
    }

    /**
     * Prima gli agenti demo, poi gli altri dal più recente.
     *
     * @param  Builder<Agent>  $query
     */
    #[Scope]
    protected function demoFirst(Builder $query): void
    {
        $query->orderByDesc('is_demo')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
