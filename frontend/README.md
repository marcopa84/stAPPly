# stAPPly — frontend (Laravel)

L'interfaccia di stAPPly: Laravel 13 con Livewire 4 (componenti a classe), Flux e Fortify,
servita da Herd su <http://stapply.test>. È la fonte di verità per utenti, agenti, permessi e
istruzioni; documenti, tabelle e chat stanno nel backend FastAPI, che il frontend chiama via HTTP
secondo il contratto in [`docs/api.md`](../docs/api.md).

Presentazione del progetto, architettura e decisioni sono nel [README principale](../README.md).

## Avvio

Servono [Laravel Herd](https://herd.laravel.com), che fornisce PHP 8.4, Composer e Node 22, e il
backend acceso (vedi [`backend/README.md`](../backend/README.md)).

```bash
cd frontend
composer install
npm ci
npm run build
cp .env.example .env
php artisan key:generate
php artisan migrate --seed      # la prima volta conferma la creazione di database/database.sqlite
herd link stapply               # il sito diventa http://stapply.test
```

`migrate --seed` crea l'utente demo `demo@stapply.test` con i due agenti demo. I documenti e le
tabelle di quegli agenti li carica il backend con `uv run python -m scripts.seed_demo`, che
richiede la chiave OpenAI. Chi entra con quelle credenziali riceve una sandbox (vedi [Demo](#demo)).

Le variabili di stAPPly nel `.env` (lette da `config/stapply.php`):

| Variabile | A cosa serve |
|---|---|
| `STAPPLY_BACKEND_URL` | indirizzo del backend, di solito `http://127.0.0.1:8001` |
| `STAPPLY_BACKEND_KEY` | la stessa chiave di `INTERNAL_API_KEY` nel `.env` del backend |
| `STAPPLY_BACKEND_TIMEOUT` | secondi di attesa per upload e chat (default 120) |
| `STAPPLY_DEMO_PASSWORD` | password pubblica della demo (default `stapply-demo`) |

## Comandi

| Comando | Cosa fa |
|---|---|
| `npm run build` | compila CSS e JS in `public/build` (da rifare dopo aver cambiato classi Tailwind nelle viste) |
| `php artisan test` | i test Pest |
| `vendor/bin/pint --test` | lo stile del codice PHP (senza `--test` lo corregge) |
| `vendor/bin/phpstan analyse --memory-limit=1G` | analisi statica con Larastan, livello 7 |
| `php artisan db:seed --class=DemoSeeder` | riporta l'utente demo (il template) e i suoi agenti allo stato iniziale |
| `php artisan stapply:prune-demo` | cancella le sandbox demo scadute, prima nel backend e poi qui (lo scheduler lo lancia ogni ora) |

I test non chiamano mai il backend: `Http::preventStrayRequests()` fa fallire ogni richiesta
senza risposta finta, e le risposte finte sono gli esempi di [`docs/contract/`](../docs/contract/)
(l'helper `contract()` in `tests/Pest.php`). Usano SQLite in memoria, ma vogliono un `.env` con
`APP_KEY` e gli asset compilati. In CI li lancia [`.github/workflows/frontend.yml`](../.github/workflows/frontend.yml).

## Demo

L'utente demo di `DemoSeeder` è il **template**: ha gli agenti `demo-valtesa` e `demo-valdoria`,
le sue credenziali sono nella home, ma nessuno lo usa direttamente. Ogni accesso con quelle
credenziali apre una **sandbox**: un utente nuovo ("Visitatore demo", con un'email
`demo-<ulid>@sandbox.stapply.invalid` che nessuno può indovinare né ricevere) con la sua copia dei
due agenti. Per ogni agente demo Laravel crea un agente con le stesse istruzioni e chiede al
backend di copiargli documenti e tabelle (`POST /agents/{agent_id}/clone`): vettori già calcolati,
quindi nessun costo. Le copie sono del visitatore: istruzioni, file, chat e agenti nuovi li cambia
come vuole, senza toccare il template né le sandbox degli altri.

- **Login.** `App\Actions\Fortify\AuthenticateUser` (registrata con `Fortify::authenticateUsing`)
  riconosce le credenziali demo e apre la sandbox con `App\Services\Demo\DemoSandbox`; per gli
  altri utenti il controllo è quello standard di Fortify. Fortify chiama la callback due volte per
  lo stesso login, quindi il risultato si conserva sulla richiesta: un login, una sandbox. Se una
  copia fallisce si annulla solo quello creato in quel tentativo, e il login spiega che la demo
  non è disponibile; oltre `demo.max_sandboxes` sandbox aperte chiede di riprovare più tardi.
- **Fine.** La sandbox scade dopo `demo.sandbox_minutes` (120) o al logout (il listener
  `ExpireDemoSandbox`). Il middleware `EndFinishedDemoSession` scollega una sandbox scaduta, e
  anche una sessione rimasta aperta sul template, e torna alla home con un messaggio.
- **Pulizia.** `stapply:prune-demo`, pianificato ogni ora in `routes/console.php`, cancella dal
  backend gli agenti di ogni sandbox scaduta e poi l'utente, con agenti, chat e messaggi. Prima di
  ogni `DELETE` controlla sul DB che l'agente non sia del template o demo. Se il backend non
  risponde la sandbox resta per il giro dopo. Herd non ha cron: ogni login demo cancella anche
  qualche sandbox scaduta (`demo.prune_on_login`), senza mai far fallire il login. In produzione
  serve il cron di `php artisan schedule:run`.
- **Blocchi.** Per template e sandbox (`DemoAccount::is()`) password, email, nome, autenticazione
  a due fattori, passkey ed eliminazione dell'account sono chiusi, sia nei componenti delle
  impostazioni sia nelle rotte di Fortify (`ProtectDemoAccount`). Una sandbox ha lo stesso hash di
  password del template, così la conferma della password porta alle impostazioni, che spiegano
  il blocco; ma non si apre con la sua email.
- **Limiti** (`App\Support\DemoLimits`, valori in `config/stapply.php` sotto `demo`): per
  sandbox al massimo 4 agenti (le due copie comprese), 10 documenti e 6 tabelle per agente, file
  fino a 2 MB; per tutte le sandbox insieme 30 messaggi di chat al minuto e 30 caricamenti
  all'ora. Gli altri utenti hanno i limiti generali.
- **Avviso.** Nel layout dell'app le sandbox vedono "Sei nella demo: puoi modificare tutto. Al
  prossimo accesso riparti dai valori iniziali.", con l'ora di scadenza.

Gli agenti del template restano protetti da `AgentPolicy` (file in sola lettura, non si
eliminano). Per cambiare quello che i visitatori trovano all'ingresso, per esempio istruzioni
corrette con la griglia di prova, si cambia `DemoSeeder` e lo si rilancia.

## Come è fatto

- `app/Services/Backend/BackendClient.php`: un metodo per ogni endpoint del contratto, risposte
  in DTO (`app/Services/Backend/Data/`). Ogni errore diventa una `BackendException` con un
  messaggio da mostrare all'utente: backend spento o lento, `detail` del backend, chiave rifiutata.
- `app/Models/Agent.php`: l'agente con il suo `backend_id` (ULID in minuscolo, o `demo-*`), che è
  anche la chiave negli URL. `AgentPolicy` lo riserva al proprietario e protegge gli agenti demo.
- `app/Livewire/Agents/`: elenco, creazione, modifica ed eliminazione; la pagina dell'agente
  (`Show`) ha una sezione per scheda, ognuna un componente a sé (`Documents`, `Tables` e
  `SqlConsole`, che compare solo se l'agente ha tabelle).
- `app/Livewire/Agents/Chat.php` (rotta `agents/{agent}/chat/{conversation?}`): le chat
  dell'utente con l'agente. Ogni `Conversation` ha un nome e un `session_id` (ULID in minuscolo)
  che il backend usa come memoria: riaprire una chat con lo stesso nome (maiuscole e minuscole
  non contano) riporta alla stessa sessione. Un messaggio parte in due richieste Livewire (`send()` lo salva, `reply()` chiede la
  risposta), così resta in pagina mentre l'agente lavora e, se il backend non risponde, si può
  riprovare. Il limite è `chat_messages_per_minute` in `config/stapply.php`.
- Sotto ogni risposta c'è la traccia: la strada (`App\Enums\AnswerRoute`), i tool chiamati con
  gli argomenti e l'avviso di `max_turns`. Il markdown della risposta passa da `Str::markdown`
  senza HTML grezzo né link non sicuri; gli id dei chunk citati diventano chip con il passaggio
  grazie a un'estensione di CommonMark (`app/Markdown/`).

## Struttura

```
app/
├── Services/Backend/     client HTTP del backend e DTO delle risposte
├── Models/               Agent, Conversation, Message, User
├── Policies/             AgentPolicy, ConversationPolicy
├── Livewire/             Dashboard e Agents/: Index, Create, Edit, Show, Documents, Tables,
│                         SqlConsole, Chat
├── Markdown/             estensione CommonMark per le citazioni [id]
├── Enums/                AnswerRoute (strada della risposta), MessageRole
├── Services/Demo/        DemoSandbox: apre e cancella le sandbox della demo
├── Actions/Fortify/      AuthenticateUser (login, sandbox demo) e le altre azioni di Fortify
├── Support/              DemoAccount (template e sandbox), DemoLimits (limiti della demo)
├── Http/Middleware/      ProtectDemoAccount (rotte che cambiano credenziali), EndFinishedDemoSession
├── Listeners/            ExpireDemoSandbox: il logout chiude la sandbox
└── Console/Commands/     stapply:prune-demo
config/stapply.php        backend, limiti, chat, demo
database/seeders/         DemoSeeder
resources/views/
├── livewire/agents/      le viste delle pagine degli agenti e la traccia della chat
└── components/           logo, wordmark st<APP>ly e la mascotte (<x-mascot state="…">)
lang/it/, lang/it.json    l'interfaccia è in italiano
tests/Feature/            Agents/, Backend/, Chat/, Demo/ (sandbox) e i test di brand, dashboard e utente demo
```
