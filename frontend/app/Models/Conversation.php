<?php

namespace App\Models;

use App\Enums\MessageRole;
use App\Services\Backend\Data\ChatReply;
use App\Services\Backend\Data\Source;
use App\Services\Backend\Data\ToolCall;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Una chat con un agente, con il nome scelto dall'utente. La memoria che l'agente
 * usa sta nel backend, sotto il session_id; qui resta la copia dei messaggi da
 * mostrare, con la traccia di ogni risposta.
 *
 * @property int $id
 * @property int $agent_id
 * @property int $user_id
 * @property string $title
 * @property string $session_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Agent $agent
 * @property-read User $user
 * @property-read Collection<int, Message> $messages
 */
#[Fillable(['user_id', 'title'])]
#[RouteKey('session_id')]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * Il ULID in minuscolo rispetta la regex di session_id del backend e, a differenza
     * dell'id numerico, non lascia indovinare le conversazioni degli altri dall'URL.
     */
    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation): void {
            if (blank($conversation->session_id)) {
                $conversation->session_id = Str::lower((string) Str::ulid());
            }
        });
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * La domanda rimasta senza risposta, se c'è: l'ultimo messaggio, quando è dell'utente.
     */
    public function pendingQuestion(): ?Message
    {
        $last = $this->messages()->latest('id')->first();

        return $last?->role === MessageRole::User ? $last : null;
    }

    public function ask(string $text): Message
    {
        return $this->messages()->create([
            'role' => MessageRole::User,
            'content' => $text,
        ]);
    }

    public function recordReply(ChatReply $reply): Message
    {
        return $this->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $reply->answer,
            'status' => $reply->status,
            'route' => $reply->route,
            'tools' => array_map(fn (ToolCall $tool) => $tool->toArray(), $reply->tools),
            'sources' => array_map(fn (Source $source) => $source->toArray(), $reply->sources),
        ]);
    }
}
