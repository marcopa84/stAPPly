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
 * Una chat con un agente. La memoria che l'agente usa sta nel backend, sotto il
 * session_id; qui resta la copia dei messaggi da mostrare, con la traccia di ogni
 * risposta.
 *
 * Il titolo nasce provvisorio dal primo messaggio; dopo la prima risposta lo scrive il
 * modello, a meno che l'utente non l'abbia già cambiato. titled_at è null finché il
 * titolo è provvisorio e nessuno ha ancora chiesto quello vero.
 *
 * @property int $id
 * @property int $agent_id
 * @property int $user_id
 * @property string $title
 * @property string $session_id
 * @property Carbon|null $titled_at
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

    /** Caratteri del titolo provvisorio, preso dal primo messaggio. */
    public const PROVISIONAL_TITLE_LENGTH = 50;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'titled_at' => 'datetime',
        ];
    }

    /**
     * Il titolo di una chat appena nata: l'inizio della prima domanda, su una riga, tagliato
     * tra due parole.
     */
    public static function provisionalTitle(string $message): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $message));

        return Str::limit($line, self::PROVISIONAL_TITLE_LENGTH, '…', preserveWords: true);
    }

    /**
     * Prende in carico la scelta del titolo: vero una volta sola per chat, anche se la
     * richiesta arriva due volte. Ogni titolo costa una chiamata al modello.
     */
    public function claimTitling(): bool
    {
        $claimed = static::query()
            ->whereKey($this->id)
            ->whereNull('titled_at')
            ->update(['titled_at' => now()]) === 1;

        if ($claimed) {
            $this->refresh();
        }

        return $claimed;
    }

    /**
     * Mette il titolo scritto dal modello, solo se c'è ancora quello provvisorio: se nel
     * frattempo l'utente ha rinominato la chat, vince il suo nome.
     */
    public function applyGeneratedTitle(string $provisional, string $title): bool
    {
        $applied = static::query()
            ->whereKey($this->id)
            ->where('title', $provisional)
            ->update(['title' => Str::limit($title, 100, '')]) === 1;

        if ($applied) {
            $this->refresh();
        }

        return $applied;
    }

    /**
     * Il nome scelto dall'utente: da qui in poi il titolo automatico non lo tocca più.
     */
    public function rename(string $title): void
    {
        $this->forceFill(['title' => $title, 'titled_at' => now()])->save();
    }

    /**
     * La prima domanda e la prima risposta, se la chat ne ha una.
     *
     * @return array{0: Message, 1: Message}|null
     */
    public function firstExchange(): ?array
    {
        $question = $this->messages()->where('role', MessageRole::User)->oldest('id')->first();
        $answer = $this->messages()->where('role', MessageRole::Assistant)->oldest('id')->first();

        return $question !== null && $answer !== null ? [$question, $answer] : null;
    }

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
