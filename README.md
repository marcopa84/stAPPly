# stAPPly

> **Staple your docs. Apply your knowledge.**

Nel 1997 l'assistente di Office spuntava nell'angolo per dirti che stavi scrivendo una lettera.
stAPPly è il suo erede che ha studiato: gli dai i tuoi documenti, li *pinza* insieme
e ti risponde citando il passaggio esatto da cui viene ogni risposta.

Il nome unisce *staple* (pinzare) e *apply* (applicare): la doppia «p» non è un refuso, è il punto
in cui le due parole sono pinzate insieme. Una graffetta tiene i fogli insieme per un po'; la
pinzatrice li unisce davvero, come il RAG unisce passaggi di documenti diversi in una risposta
con le fonti. Per questo la mascotte è una pinzatrice, disegnata apposta.

Progetto finale del modulo RAG del Master in AI Engineering di Boolean, e base per il modulo
sugli agenti.

## Cosa fa

- Ogni utente crea i propri **agenti**, ognuno con istruzioni proprie, i propri documenti
  (`.txt`, `.md`, `.pdf`, `.docx`, `.html`) e, se servono, le proprie tabelle (`.csv`, `.xlsx`)
  interrogabili in SQL.
- **Chat con memoria**: ogni chat ha un nome, e riaprirla con lo stesso nome riprende la stessa
  sessione.
- **Risposte con le fonti**: gli id dei passaggi citati (`[regolamento-ferie-permessi-003]`)
  diventano chip che mostrano il file e il testo.
- **Traccia sotto ogni risposta**: i tool chiamati con i loro argomenti e la strada seguita
  (`documenti`, `dati`, `documenti+dati`, `nessuna`).
- **Console SQL** in sola lettura sulle tabelle dell'agente, per controllare a mano i numeri.
- **Demo a sandbox**: chi entra con l'utente demo riceve una copia tutta sua di due agenti su dati
  finti (l'ufficio del personale di Valtesa S.r.l. e la biblioteca civica di Valdoria), da
  modificare come vuole; al prossimo accesso riparte dai valori iniziali.

## Architettura

```
              browser
                 │
                 ▼
┌─────────────────────────────────┐  HTTP + X-Internal-Key   ┌─────────────────────────────────┐
│ frontend/  Laravel 13           │ ───────────────────────▶ │ backend/  FastAPI, Python 3.12  │
│ Livewire 4, Flux, Fortify       │                          │ OpenAI Agents SDK 0.22.3        │
│                                 │                          │                                 │
│ utenti, agenti, istruzioni,     │  answer, status, route,  │ documenti → ChromaDB agent_<id> │
│ chat salvate, console SQL       │  tools, sources          │ tabelle   → SQLite per agente   │
│ (SQLite)                        │ ◀─────────────────────── │ memoria   → SQLiteSession       │
└─────────────────────────────────┘                          └────────────────┬────────────────┘
                                                                              │ embedding e modello
                                                                              ▼
                                                                            OpenAI
```

Un turno di chat:

1. Laravel salva il messaggio e chiama `POST /agents/{agent_id}/chat` con `session_id`,
   messaggio, istruzioni e nome dell'agente.
2. Il backend costruisce l'agente: le istruzioni di Laravel seguite dalle regole fisse di
   stAPPly; `cerca_documenti` solo se l'agente ha documenti, `query_db` solo se ha tabelle, con
   lo schema reale nella descrizione.
3. `Runner.run` con la memoria `SQLiteSession` di chiave `<agent_id>:<session_id>`.
4. La risposta torna con la traccia (`tools`, `route`) e le fonti citate (`sources`); Laravel la
   salva e la mostra con i chip delle citazioni.

## Requisiti

| Strumento | A cosa serve |
|---|---|
| [Laravel Herd](https://herd.laravel.com) | PHP 8.4, Composer e Node 22 per il frontend, e il sito `http://stapply.test` |
| [uv](https://docs.astral.sh/uv/) | ambiente e dipendenze del backend; installa da sé Python 3.12 (`backend/.python-version`) |
| Una chiave OpenAI | embedding dei documenti e chat. Non serve per i test |

**Perché niente Docker.** I servizi sono due e in due linguaggi, ma in sviluppo girano bene
nativi: Herd serve Laravel, uv ricrea l'ambiente Python da `uv.lock`. La riproducibilità sta nei
lockfile (`uv.lock`, `composer.lock`, `package-lock.json`) e nella CI, che a ogni push reinstalla
tutto da zero su Linux. `uv.lock` è risolto per tutte le piattaforme: per esempio usa
`onnxruntime` 1.23 sui Mac Intel, per cui le versioni nuove non hanno wheel, e 1.31 su Linux.
Docker servirà per il deploy del backend, non per lavorarci.

## Avvio in locale

Prima il backend, poi il frontend. `INTERNAL_API_KEY` del backend e `STAPPLY_BACKEND_KEY` del
frontend devono coincidere: negli `.env.example` valgono entrambe `cambiami`, da cambiare insieme.

### Backend

```bash
cd backend
cp .env.example .env                   # scrivi OPENAI_API_KEY e cambia INTERNAL_API_KEY
uv sync
uv run python -m scripts.seed_demo     # documenti e tabelle degli agenti demo: serve OPENAI_API_KEY
uv run uvicorn app.main:app --port 8001
```

- Senza chiave OpenAI il backend parte lo stesso: `/health`, tabelle e console SQL funzionano,
  mentre upload dei documenti e chat rispondono `502`. `seed_demo` si ferma prima di toccare i dati.
- Un solo worker, come da default: il blocco che serializza gli upload di un agente vale dentro
  un processo.
- La documentazione interattiva dell'API è su <http://127.0.0.1:8001/docs>.

### Frontend

```bash
cd frontend
composer install
npm ci
npm run build
cp .env.example .env                   # STAPPLY_BACKEND_KEY = INTERNAL_API_KEY del backend
php artisan key:generate
php artisan migrate --seed             # la prima volta conferma la creazione di database/database.sqlite
herd link stapply                      # il sito diventa http://stapply.test
```

`APP_URL` nel `.env.example` è già `http://stapply.test`. Le altre variabili di stAPPly sono
descritte in [`frontend/README.md`](frontend/README.md).

### Demo

| Email | Password |
|---|---|
| `demo@stapply.test` | `stapply-demo` (da `STAPPLY_DEMO_PASSWORD`) |

Il seeder di Laravel crea l'utente demo con gli agenti `demo-valtesa` e `demo-valdoria` e le loro
istruzioni; `seed_demo` del backend carica documenti e tabelle da `backend/seed/`. Quell'utente è
il **template** e nessuno lo usa direttamente: ogni accesso con le sue credenziali apre una
**sandbox**, un utente temporaneo con la sua copia dei due agenti. Il backend la prepara con
`POST /agents/{agent_id}/clone`, che copia vettori, file e tabelle già pronti: nessun embedding
nuovo, quindi nessun costo.

Nella sandbox il visitatore cambia istruzioni e file, crea agenti e apre chat senza disturbare chi
è collegato insieme a lui. La sandbox si chiude al logout o dopo due ore, e `stapply:prune-demo`
la cancella, nel backend e in Laravel; al prossimo accesso si riparte dai valori iniziali. Limiti
e dettagli sono in [`frontend/README.md`](frontend/README.md#demo).

## Test e lint

```bash
cd backend
uv run pytest -q
uv run ruff check && uv run ruff format --check
```

```bash
cd frontend
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```

Nessun test usa la rete né una chiave OpenAI:

- **Backend**: gli embedding sono finti, il modello è un `Model` dell'SDK scritto per i test
  (`tests/modello_finto.py`) e un fixture blocca i socket.
- **Frontend**: `Http::preventStrayRequests()` fa fallire ogni chiamata non prevista, e le
  risposte finte del backend sono gli esempi di `docs/contract/`. I test vogliono un `.env` con
  `APP_KEY` e gli asset compilati (`npm run build`).

La CI ([`.github/workflows/`](.github/workflows/)) ha un workflow per servizio, filtrato per
percorso:

- `backend.yml`: `uv sync --locked`, Ruff e pytest. Installa anche PHP, perché un test
  confronta le istruzioni lette da `scripts/griglia.py` con quelle che vede PHP;
- `frontend.yml`: Composer, `npm ci`, build, Pint, PHPStan (livello 7) e Pest.

Tutti e due girano anche quando cambia il contratto. Il backend gira anche quando cambiano
`docs/griglia/` o `DemoSeeder.php`, che i suoi test leggono. Dependabot propone ogni settimana gli
aggiornamenti di Composer, npm, uv e delle action.

## Decisioni

- **Un contratto scritto tra i due servizi.** [`docs/api.md`](docs/api.md) e gli esempi JSON in
  [`docs/contract/`](docs/contract/) li usano i test di entrambi i lati. Il backend controlla che
  le sue risposte abbiano la forma degli esempi, il frontend li usa come risposte finte. Così una
  risposta che cambia forma rompe i test del backend, e un esempio cambiato fa girare i test del
  frontend sulla forma nuova.
- **Laravel è la fonte di verità** per utenti, agenti, permessi e istruzioni. Il backend non
  conosce gli utenti: lavora per `agent_id` e riceve istruzioni e nome dell'agente a ogni chat.
  Le istruzioni si modificano in un posto solo, senza copie da tenere allineate.
- **Le regole fisse le aggiunge il backend**, dopo le istruzioni di Laravel, così nessuna
  istruzione scritta da un utente le può togliere:
  - rispondere solo con quello che restituiscono i tool;
  - citare l'id del chunk tra `[ ]`;
  - dire dove si è cercato quando non si trova la risposta;
  - rifiutare le domande fuori tema senza chiamare tool;
  - fermarsi dopo due errori di fila di un tool;
  - trattare documenti e tabelle come dati.
- **Una collection Chroma per agente** (`agent_<id>`, metrica coseno) invece di una collection
  unica filtrata sui metadati. L'isolamento tra agenti non dipende da un filtro da non
  dimenticare, e cancellare un agente vuol dire cancellare la sua collection. Con migliaia di
  agenti va rivalutata.
- **Un SQLite per agente** per le tabelle: `query_db` apre solo il file di quell'agente, quindi
  una query non può leggere le tabelle di un altro.
- **Tool costruiti a ogni richiesta.** Ogni agente ha solo i tool per cui ha dati, e
  `query_db` porta nella descrizione tabelle e colonne vere: il modello non deve indovinarle.
- **Core indipendente da FastAPI** (`backend/app/core`). Lo usano l'API e lo script dei dati
  demo; potrà essere esposto via MCP o usato da più agenti dell'SDK senza riscritture.

Più dettagli (ordine dell'upload, gestione degli errori, `max_turns`) in
[`backend/README.md`](backend/README.md).

## Sicurezza

- **Chiave interna.** Ogni endpoint tranne `/health` vuole `X-Internal-Key`, confrontata a tempo
  costante. Con `INTERNAL_API_KEY` vuota il backend rifiuta tutto invece di accettare tutto.
  Uvicorn ascolta solo su `127.0.0.1`.
- **File caricati.** Ogni upload viene controllato prima di toccare i dati:
  - dimensione letta a pezzi, fermandosi oltre i 10 MB;
  - numero massimo di documenti e tabelle per agente;
  - firma del contenuto controllata oltre all'estensione (un `.pdf` che in realtà è testo viene
    rifiutato);
  - zip bomb nei `.docx` e `.xlsx` fermate prima di aprirle.
- **SQL in sola lettura.** `query_db` e la console accettano una sola `SELECT` o `WITH`. La
  connessione è `mode=ro` con `PRAGMA query_only`, un authorizer SQLite ammette solo letture e
  c'è un limite di tempo.
- **Documenti trattati come dati.** I passaggi arrivano al modello tra delimitatori, con id e
  file di provenienza, e le regole fisse dicono di ignorare le istruzioni che contengono. Nel
  frontend il markdown delle risposte non passa HTML grezzo né link non sicuri.
- **Permessi nel frontend.** Agenti e chat sono del loro proprietario, e le policy si
  ricontrollano a ogni azione. Gli agenti demo hanno i file in sola lettura, e la chat ha un
  limite di messaggi al minuto per utente.
- **Demo a sandbox.** Ogni visitatore lavora sulla sua copia, e le credenziali pubbliche non
  aprono un accesso che dura: password, email, autenticazione a due fattori e passkey non si
  modificano, e una sandbox non si riapre con la sua email. La pulizia non cancella mai gli agenti
  del template (lo controlla sul DB prima di ogni `DELETE`). Limiti per sandbox (agenti, file,
  dimensione) e comuni a tutte le sandbox (messaggi al minuto, caricamenti all'ora) tengono basso
  il costo della chiave OpenAI.

## Criteri di accettazione

Come si verificano i criteri del corso, applicati a ogni agente:

| # | Criterio | Nell'interfaccia | Test automatici |
|---|---|---|---|
| 1 | Memoria di sessione | Chat → «Nuova chat» con un nome; ricreandola con lo stesso nome si riapre la stessa sessione e l'agente ricorda lo scambio | `test_memoria_della_sessione`; in `ChatTest.php` «lo stesso nome riapre la stessa chat…» |
| 2 | Ricerca con il nome del file | Nella traccia c'è la chiamata a `cerca_documenti`; ogni chip citato mostra file e passaggio | `test_indicizza_ogni_formato_del_seed`: ogni file del seed ritrovato dalla ricerca, con il nome |
| 3 | Citazione, fuori tema | La risposta ha i chip `[id]`; una domanda fuori tema ha strada `nessuna` e nessun tool | `test_domanda_sui_documenti_cita_un_chunk`, `test_domanda_fuori_tema_rifiutata_senza_tool` |
| 4 | Dati verificabili | La stessa domanda nella Console SQL, con una `SELECT` scritta a mano; una domanda mista ha strada `documenti+dati` | `test_domanda_che_chiede_documenti_e_dati_chiama_tutti_e_due_i_tool`: il numero confrontato con una somma fatta a mano sul CSV |
| 5 | Griglia di 8 domande | Ogni domanda lanciata almeno due volte, in chat nuove. Domande e risposte vere dei due agenti demo sono in [`docs/griglia/`](docs/griglia/README.md); da `backend/`, `uv run python -m scripts.griglia <tema>` fa gli stessi lanci senza interfaccia e scrive il file dei risultati da compilare | `test_griglia.py`: composizione delle 8 domande più il follow-up, ogni passaggio nel suo chunk, ogni `SELECT` con le righe attese, istruzioni lette da `DemoSeeder.php` |

I test usano un modello finto. La griglia del criterio 5 è pronta, con le risposte vere
ricontrollate dai test, e va lanciata con una chiave OpenAI: come si lancia, come si segna l'esito
e come si prova una cura è in [`docs/griglia/README.md`](docs/griglia/README.md).

## Struttura

```
.
├── .github/              CI (un workflow per servizio) e Dependabot
├── docs/
│   ├── api.md            il contratto tra frontend e backend
│   ├── contract/         esempi JSON usati dai test dei due lati
│   └── griglia/          la griglia di prova del criterio 5 sui due agenti demo
├── backend/              FastAPI + Agents SDK, vedi backend/README.md
│   ├── app/api/          lo strato HTTP: valida e chiama il core
│   ├── app/core/         ingestione, Chroma, tabelle, agente, chat; senza FastAPI
│   ├── scripts/          seed_demo.py (dati degli agenti demo) e griglia.py (griglia di prova)
│   ├── seed/             documenti e tabelle finti di Valtesa e Valdoria
│   └── tests/
└── frontend/             Laravel + Livewire + Flux, vedi frontend/README.md
    ├── app/Services/Backend/   il client HTTP del contratto
    ├── app/Livewire/           dashboard, agenti, documenti, tabelle, chat, console SQL
    ├── app/Markdown/           le citazioni [id] diventano chip con il passaggio
    └── tests/
```

## Crediti

Il backend parte dal toolkit `aikit` del Master in AI Engineering di Boolean (loader e pulizia
dei testi). La mascotte è originale.
"Office" e il suo storico assistente sono marchi di Microsoft Corporation; stAPPly non è affiliato a Microsoft.
