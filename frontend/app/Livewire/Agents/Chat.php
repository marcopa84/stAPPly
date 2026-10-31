<?php

namespace App\Livewire\Agents;

use App\Models\Agent;
use App\Models\Conversation;
use App\Services\Backend\BackendClient;
use App\Services\Backend\BackendException;
use App\Support\DemoAccount;
use App\Support\DemoLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * La chat con un agente: le conversazioni dell'utente con quell'agente e i messaggi di
 * quella aperta, con sotto ogni risposta la traccia (strada, tool chiamati, passaggi
 * citati) che serve a verificare i criteri di CONSEGNA.md.
 *
 * Un messaggio parte in due richieste: send() lo salva e torna subito, così compare
 * mentre l'agente lavora; reply() chiede la risposta al backend. Se il backend non
 * risponde la domanda resta salvata, con il motivo e il pulsante per riprovare.
 */
class Chat extends Component
{
    #[Locked]
    public Agent $agent;

    #[Locked]
    public ?Conversation $conversation = null;

    /** Il nome della chat da aprire o da creare. */
    public string $title = '';

    public string $message = '';

    /**
     * Acceso da send() e retry(), spento da reply(): così reply() chiama il backend una
     * volta per ogni messaggio contato dal limite, e chiamarla dal browser non basta.
     */
    #[Locked]
    public bool $awaitingReply = false;

    /** Perché l'ultima domanda è rimasta senza risposta. */
    #[Locked]
    public ?string $error = null;

    public ?string $deleteError = null;

    public function mount(Agent $agent, ?Conversation $conversation = null): void
    {
        $this->authorize('view', $agent);

        if ($conversation !== null) {
            // Un session_id valido ma di un altro agente non apre niente.
            abort_unless($conversation->agent()->is($agent), 404);

            $this->authorize('view', $conversation);
        }

        $this->agent = $agent;
        $this->conversation = $conversation;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'message' => ['required', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'title.required' => 'Dai un nome alla chat: con lo stesso nome la ritrovi, e l\'agente ricorda la conversazione.',
            'title.max' => 'Il nome della chat può avere al massimo 100 caratteri.',
            'message.required' => 'Scrivi una domanda.',
            'message.max' => 'Il messaggio può avere al massimo 4000 caratteri.',
        ];
    }

    /**
     * Apre la chat con questo nome, oppure la crea. Lo stesso nome riporta alla stessa
     * sessione del backend, ed è così che l'agente ricorda lo scambio (criterio 1).
     * Maiuscole e minuscole non contano ("ferie 2026" riapre "Ferie 2026", che resta
     * scritta com'era): il confronto è in PHP perché lower() di SQLite ignora gli accenti.
     */
    public function open(): void
    {
        $this->authorize('view', $this->agent);

        $this->title = trim($this->title);
        $this->validateOnly('title');

        $conversation = $this->agent->conversations()
            ->whereBelongsTo(Auth::user())
            ->get()
            ->first(fn (Conversation $existing): bool => Str::lower($existing->title) === Str::lower($this->title))
            ?? $this->agent->conversations()->create(['user_id' => Auth::id(), 'title' => $this->title]);

        $this->redirectRoute('agents.chat', ['agent' => $this->agent, 'conversation' => $conversation], navigate: true);
    }

    public function send(): void
    {
        $conversation = $this->writableConversation();

        $this->message = trim($this->message);
        $this->validateOnly('message');

        $reason = $this->throttle();

        if ($reason !== null) {
            $this->addError('message', $reason);

            return;
        }

        $conversation->ask($this->message);

        $this->reset('message', 'error');
        $this->expectReply();
    }

    /**
     * Rimanda al backend la domanda rimasta senza risposta.
     */
    public function retry(): void
    {
        if ($this->writableConversation()->pendingQuestion() === null) {
            return;
        }

        $reason = $this->throttle();

        if ($reason !== null) {
            $this->error = $reason;

            return;
        }

        $this->error = null;
        $this->expectReply();
    }

    /**
     * Chiede al backend la risposta all'ultima domanda e la salva con la sua traccia.
     * Il tempo massimo di PHP lo allunga il client, in base al suo timeout.
     */
    public function reply(BackendClient $backend): void
    {
        if (! $this->awaitingReply) {
            return;
        }

        $this->awaitingReply = false;

        $conversation = $this->writableConversation();
        $question = $conversation->pendingQuestion();

        if ($question === null) {
            return;
        }

        try {
            $reply = $backend->chat(
                agentId: $this->agent->backend_id,
                sessionId: $conversation->session_id,
                message: $question->content,
                instructions: $this->agent->instructions,
                agentName: $this->agent->name,
            );
        } catch (BackendException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $conversation->recordReply($reply);
    }

    /**
     * Prima si dimentica la sessione nel backend, poi la conversazione: se il backend
     * non risponde resta tutto com'è, e il motivo compare nel modal.
     */
    public function delete(BackendClient $backend): void
    {
        $conversation = $this->conversation;

        abort_if($conversation === null, 404);
        $this->authorize('delete', $conversation);

        try {
            $backend->deleteSession($this->agent->backend_id, $conversation->session_id);
        } catch (BackendException $e) {
            $this->deleteError = $e->getMessage();

            return;
        }

        $conversation->delete();

        session()->flash('status', "Chat «{$conversation->title}» eliminata.");

        $this->redirectRoute('agents.chat', $this->agent, navigate: true);
    }

    public function render(): View
    {
        $messages = $this->conversation?->messages()->oldest('id')->get() ?? new Collection;

        return view('livewire.agents.chat', [
            'conversations' => $this->agent->conversations()
                ->whereBelongsTo(Auth::user())
                ->latest('updated_at')
                ->latest('id')
                ->get(),
            'messages' => $messages,
            'pending' => $messages->last()?->isFromUser() ?? false,
        ])->title($this->conversation === null
            ? "Chat con {$this->agent->name}"
            : "{$this->conversation->title} · {$this->agent->name}");
    }

    /**
     * La conversazione aperta, se l'utente può scriverci.
     */
    private function writableConversation(): Conversation
    {
        $conversation = $this->conversation;

        abort_if($conversation === null, 404);
        $this->authorize('update', $conversation);

        return $conversation;
    }

    /**
     * La risposta arriva con una seconda richiesta: intanto la pagina mostra la domanda
     * e la mascotte che pensa.
     */
    private function expectReply(): void
    {
        $this->awaitingReply = true;

        $this->js('$wire.reply()');
    }

    /**
     * Conta un messaggio nel limite al minuto dell'utente e, per le sandbox demo, in
     * quello comune a tutte le sandbox. Se un limite è già raggiunto non conta niente
     * e restituisce il motivo da mostrare.
     */
    private function throttle(): ?string
    {
        $key = 'chat-messages:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, Config::integer('stapply.chat_messages_per_minute'))) {
            $seconds = RateLimiter::availableIn($key);

            return 'Hai mandato troppi messaggi in poco tempo: riprova tra '
                .($seconds === 1 ? 'un secondo' : "{$seconds} secondi").'.';
        }

        if (DemoAccount::isSandbox(Auth::user())) {
            $reason = DemoLimits::hitChatMessage();

            if ($reason !== null) {
                return $reason;
            }
        }

        RateLimiter::hit($key);

        return null;
    }
}
