<?php

namespace App\Models;

use App\Enums\AnswerRoute;
use App\Enums\MessageRole;
use App\Markdown\CitationExtension;
use App\Services\Backend\Data\ChatReply;
use App\Services\Backend\Data\Source;
use App\Services\Backend\Data\ToolCall;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Un messaggio di una conversazione. Le risposte dell'agente conservano anche la
 * traccia (stato, strada, tool chiamati) e i passaggi citati, così la griglia di
 * prova si può rileggere a distanza di tempo.
 *
 * @property int $id
 * @property int $conversation_id
 * @property MessageRole $role
 * @property string $content
 * @property string|null $status `ok` o `max_turns`; null per i messaggi dell'utente
 * @property string|null $route documenti, dati, documenti+dati o nessuna; null per i messaggi dell'utente
 * @property list<array{name: string, arguments: array<string, mixed>}>|null $tools
 * @property list<array{id: string, document_id: string, filename: string, text: string}>|null $sources
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Conversation $conversation
 */
#[Fillable(['role', 'content', 'status', 'route', 'tools', 'sources'])]
#[Touches('conversation')]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'tools' => 'array',
            'sources' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isFromUser(): bool
    {
        return $this->role === MessageRole::User;
    }

    public function reachedMaxTurns(): bool
    {
        return $this->status === ChatReply::STATUS_MAX_TURNS;
    }

    /**
     * Null anche se il backend manda una strada che il contratto non prevede: la traccia la mostra com'è.
     */
    public function answerRoute(): ?AnswerRoute
    {
        return $this->route === null ? null : AnswerRoute::tryFrom($this->route);
    }

    /**
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return array_map(ToolCall::fromArray(...), $this->tools ?? []);
    }

    /**
     * @return list<Source>
     */
    public function citedSources(): array
    {
        return array_map(Source::fromArray(...), $this->sources ?? []);
    }

    /**
     * La risposta dell'agente in HTML. Viene da un modello che ha letto documenti
     * caricati da chiunque, quindi il markdown perde l'HTML grezzo e i link pericolosi;
     * gli id dei passaggi citati diventano chip che aprono il passaggio.
     */
    public function answerHtml(): HtmlString
    {
        return new HtmlString(Str::markdown($this->content, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ], [new CitationExtension($this->citedSources())]));
    }
}
