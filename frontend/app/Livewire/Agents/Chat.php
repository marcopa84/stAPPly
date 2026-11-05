<?php

namespace App\Livewire\Agents;

use App\Models\Agent;
use App\Models\Conversation;
use App\Services\Backend\BackendClient;
use App\Services\Backend\BackendException;
use App\Support\UsageLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * La chat con un agente: lo storico delle chat dell'utente con quell'agente e i messaggi
 * di quella aperta, con sotto ogni risposta la traccia (strada, tool chiamati, passaggi
 * citati) che serve a verificare i criteri di CONSEGNA.md.
 *
 * Come nelle app di chat, si comincia a scrivere senza dare un nome: la chat nasce con la
 * prima domanda, con un titolo provvisorio, e dopo la prima risposta il modello ne scrive
 * uno (nameConversation). L'utente la può rinominare quando vuole. Riaprendola dallo
 * storico si torna alla stessa sessione del backend, ed è così che l'agente ricorda lo
 * scambio (criterio 1).
 *
 * Un messaggio parte in due richieste: send() lo salva e torna subito, così compare
 * mentre l'agente lavora; reply() chiede la risposta al backend. Se il backend non
 * risponde la domanda resta salvata, con il motivo e il pulsante per riprovare.
 *
 * Il limite di messaggi si conta in send() e retry(), il costo sta in reply(): per
 * questo reply() prende in carico la domanda nel DB (Message::claimForReply), e una
 * domanda contata una volta arriva al backend una volta sola, anche se il browser
 * rimanda la stessa richiesta.
 */
class Chat extends Component
{
    #[Locked]
    public Agent $agent;

    #[Locked]
    public ?Conversation $conversation = null;

    public string $message = '';

    /** Il titolo che si sta scrivendo per la chat aperta; null quando non la si rinomina. */
    public ?string $newTitle = null;

    /**
     * Acceso quando una domanda aspetta la risposta, spento da reply() o replyFailed():
     * mentre è acceso la pagina non offre Riprova e lancia reply(). Non protegge il backend:
     * vive nello snapshot, che il browser può rimandare; lo fa la presa in carico della
     * domanda in reply().
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

        // Una domanda salvata e mai mandata al backend parte da sola: è la prima di una chat
        // appena nata, che arriva qui dal redirect di send(), o una rimasta lì perché la
        // pagina si è chiusa subito. È già contata nei limiti, e reply() la prende in carico
        // una volta sola. Una domanda già mandata e rimasta senza risposta offre Riprova.
        $question = $conversation?->pendingQuestion();
        $this->awaitingReply = $question !== null && $question->reply_started_at === null;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4000'],
            'newTitle' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'message.required' => 'Scrivi una domanda.',
            'message.max' => 'Il messaggio può avere al massimo 4000 caratteri.',
            'newTitle.required' => 'Il titolo della chat non può essere vuoto.',
            'newTitle.max' => 'Il titolo della chat può avere al massimo 100 caratteri.',
        ];
    }

    public function send(): void
    {
        $this->message = trim($this->message);

        if ($this->conversation === null) {
            $this->start();

            return;
        }

        $conversation = $this->writableConversation();

        $this->validateOnly('message');

        $reason = $this->throttle();

        if ($reason !== null) {
            $this->addError('message', $reason);

            return;
        }

        $conversation->ask($this->message);

        $this->reset('message', 'error');
        $this->awaitingReply = true;
    }

    /**
     * Rimanda al backend la domanda rimasta senza risposta.
     */
    public function retry(): void
    {
        $question = $this->writableConversation()->pendingQuestion();

        if ($question === null) {
            return;
        }

        $reason = $this->throttle();

        if ($reason !== null) {
            $this->error = $reason;

            return;
        }

        // Il tentativo è contato: reply() può prendere di nuovo in carico la domanda.
        $question->releaseForReply();

        $this->error = null;
        $this->awaitingReply = true;
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

        // Già presa in carico: è lo stesso snapshot rimandato, o arrivato due volte.
        if ($question === null || ! $question->claimForReply()) {
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

        // Il titolo con un'altra richiesta, così la risposta compare subito.
        if ($conversation->titled_at === null) {
            $this->js('$wire.nameConversation()');
        }
    }

    /**
     * La richiesta di reply() è fallita prima di arrivare a una risposta di Livewire
     * (errore 500, sessione scaduta, rete): senza questa chiamata la pagina resterebbe
     * in attesa, senza Riprova fino al ricaricamento.
     */
    public function replyFailed(): void
    {
        if (! $this->awaitingReply) {
            return;
        }

        $this->awaitingReply = false;
        $this->error = 'La risposta non è arrivata: puoi riprovare.';
    }

    /**
     * Dopo la prima risposta il modello scrive il titolo della chat. Una volta sola per
     * chat, anche se la richiesta arriva più volte: ogni titolo è una chiamata al modello.
     * Se il backend non risponde resta il titolo provvisorio, e un nome dato nel frattempo
     * dall'utente non viene sovrascritto.
     */
    public function nameConversation(BackendClient $backend): void
    {
        $conversation = $this->writableConversation();
        $exchange = $conversation->firstExchange();

        if ($exchange === null || ! $conversation->claimTitling()) {
            return;
        }

        [$question, $answer] = $exchange;
        $provisional = $conversation->title;

        try {
            $title = $backend->title($question->content, $answer->content);
        } catch (BackendException) {
            return;
        }

        $conversation->applyGeneratedTitle($provisional, $title);
    }

    public function startRename(): void
    {
        $this->newTitle = $this->writableConversation()->title;
        $this->resetValidation('newTitle');
    }

    public function rename(): void
    {
        $conversation = $this->writableConversation();

        $this->newTitle = trim((string) $this->newTitle);
        $this->validateOnly('newTitle');

        $conversation->rename($this->newTitle);

        $this->newTitle = null;
    }

    public function cancelRename(): void
    {
        $this->newTitle = null;
        $this->resetValidation('newTitle');
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
            ? "Nuova chat · {$this->agent->name}"
            : "{$this->conversation->title} · {$this->agent->name}");
    }

    /**
     * La prima domanda di una chat nuova: la chat nasce qui, con il titolo provvisorio, e
     * la pagina passa al suo indirizzo, dove la risposta parte da sola (vedi mount). Prima
     * si valida e si conta il messaggio: un invio fermato non lascia chat vuote nello storico.
     */
    private function start(): void
    {
        $this->authorize('view', $this->agent);

        $this->validateOnly('message');

        $reason = $this->throttle();

        if ($reason !== null) {
            $this->addError('message', $reason);

            return;
        }

        $conversation = $this->agent->conversations()->create([
            'user_id' => Auth::id(),
            'title' => Conversation::provisionalTitle($this->message),
        ]);
        $conversation->ask($this->message);

        $this->redirectRoute('agents.chat', ['agent' => $this->agent, 'conversation' => $conversation], navigate: true);
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
     * Conta un messaggio nei limiti dell'utente (e, per le sandbox, in quello comune a
     * tutte). Se un limite è già raggiunto non conta niente e restituisce il motivo.
     */
    private function throttle(): ?string
    {
        return UsageLimits::hitChatMessage(Auth::user());
    }
}
