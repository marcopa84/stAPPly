# stAPPly — dove siamo e cosa resta

Aggiornato il 5-11-2026.

## Fatto

- **Progetto completo** su `main` e su GitHub (`git@github.com:marcopa84/stAPPly.git`), in commit
  per argomento: contratto API, backend (ingestione, tabelle, agente, chat, API, clone, seed,
  griglia), frontend (starter kit, brand e mascotte, agenti, chat con traccia e console SQL,
  sandbox demo), CI e README.
- **Verificato dal vivo con la chiave vera** (`backend/.env`):
  - seed demo (236 chunk, 6 tabelle);
  - 6 domande della griglia del docente su Valtesa, tutte giuste: citazioni `[id]`, route corrette,
    rifiuto fuori tema senza tool, follow-up con memoria;
  - chat nel browser: `send()` → `reply()`, traccia, popover delle citazioni.
- **Sandbox demo:** ogni accesso con le credenziali demo crea un utente temporaneo con la sua copia
  degli agenti demo, clonata dal backend senza nuovi embedding. Le sandbox scadono e vengono
  pulite; password, email, doppia autenticazione e passkey della demo sono bloccate.
- **Revisione avversariale chiusa.** Cinque revisori (correttezza backend, sicurezza, correttezza
  frontend, requisiti, test) hanno trovato 46 problemi; 42 sono stati confermati da un verificatore
  indipendente e sono tutti corretti nel codice, con un test ciascuno (`repo-01`, sulla storia git,
  è stato chiuso il 5-11-2026: vedi sotto).
  Tra i più seri:
  - tetto ai chunk per documento prima degli embedding;
  - tetto alla dimensione dei risultati SQL e alle celle delle tabelle;
  - xlsx con dimensioni ricalcolate;
  - pulizia dei PDF per pagina, che non perde le celle delle tabelle;
  - HTML e TXT dei file utente senza le ricette di Wikipedia e Gutenberg;
  - tabelle Word lette dal .docx;
  - memoria della chat limitata agli ultimi turni;
  - tempi del modello sotto il timeout di Laravel;
  - eliminazione dell'account che cancella anche i dati nel backend;
  - email salvate in minuscolo;
  - risposta della chat presa in carico una volta sola;
  - immagini markdown rese come testo;
  - limiti per IP alle sandbox e limiti di costo per tutti gli utenti;
  - registrazione con limite di tentativi.

- **Verifica finale (1-11-2026)** su un clone pulito da GitHub, rifacendo i passi della CI:
  backend 440 test con `CI=1` (nessuno saltato) e ruff pulito; frontend con build, Pint e PHPStan
  puliti e 288 test; `migrate --seed` su un DB nuovo crea il template e i due agenti demo.
- **Prova dal vivo della sandbox** (backend vero con le tabelle seed, frontend con
  `php artisan serve`, login con curl, senza OpenAI):
  - due login demo danno due sandbox distinte, con cloni distinti, e ogni clone ha le sue tabelle;
  - la dashboard mostra l'avviso della sandbox;
  - dopo logout e `stapply:prune-demo` la sandbox e i suoi cloni nel backend spariscono, mentre
    l'altra sandbox e il template restano intatti.

  Lo script è in `scratchpad/e2e_sandbox.sh` della sessione, non nel repo.

- **Seed demo rifatto** con la nuova pulizia dei PDF (1-11-2026).
- **Griglia vera, criterio 5:**
  - Valtesa 18/18 e Valdoria 17/18 al primo giro, due lanci per domanda;
  - una cura (le risposte sì/no di Valdoria) e Valdoria rilanciata con tre lanci: 27/27;
  - risultati e cura in `docs/griglia/` e nel README; template di Laravel riallineato con
    `db:seed --class=DemoSeeder`.
- **CLAUDE.md** aggiornato con la sandbox demo.
- **CI su GitHub** verde, backend e frontend, sugli ultimi push.

- **Cura 2 della griglia (5-11-2026):** lo schema di `query_db` elenca i valori delle colonne di
  testo con pochi valori; il modello indovinava `'in attesa di approvazione'` e in Docker aveva
  risposto «0». Rilanciate le due griglie con tre lanci: Valtesa 27/27, Valdoria 27/27.
- **Docker (5-11-2026):** `compose.yaml` con backend (uv), frontend (FrankenPHP) e scheduler,
  con gli stessi `.env` dello sviluppo. Provato dal vivo su http://localhost:8080: seed demo al primo
  avvio, login demo con sandbox, chat con traccia, tutto nel browser. CI `docker.yml` che
  costruisce le immagini.

- **Chat come nelle app di chat (5-11-2026):** si scrive subito, la chat nasce con la prima
  domanda, il titolo lo scrive il modello dopo la prima risposta (`POST /titles`), si rinomina dal
  titolo, si riapre dallo storico. Provato nel browser in Docker.
- **Cura 3 della griglia (5-11-2026):** la data di oggi nelle regole fisse. Valdoria 27/27, ora con
  la scadenza del prestito 620; Valtesa 25/27 in due giri, con errori che cambiano da un giro
  all'altro sulle domande instabili anche nel corso (vedi `docs/griglia/README.md`).
- **Storia git riordinata (5-11-2026, force push confermato da Marco):**
  - i dati finti del seed arrivano prima del codice che li usa nei test;
  - configurazione, ingestione e tabelle in un commit (i test sono stati scritti insieme);
  - il clone prima dell'API che lo importa;
  - la griglia dopo il frontend, perché i suoi test leggono `DemoSeeder.php`;
  - lo starter kit è quello esatto uscito da `laravel new` il 31-10 (commit `143e0c1` di
    `laravel/livewire-starter-kit`);
  - agenti, chat e sandbox demo in un commit, perché il codice dell'uno usa quello degli altri.

  Ogni commit del repo si avvia e passa i suoi test (backend da 230 a 464 test, frontend da 34 a
  303), e l'albero finale è identico a prima. Il contratto di `/clone` resta nel commit iniziale
  del contratto: la storia è scritta «contratto prima», come si è lavorato davvero.
- **Docker deciso:** Herd e uv per lo sviluppo, `compose.yaml` per far girare tutto.
- **CLAUDE.md** resta nel repo.

## Da fare

1. **Cure possibili per Valtesa**, le stesse del docente, una alla volta e rilanciando tutto: la
   riga sulle omonime nelle istruzioni (domanda 4, «36 giorni») e K 8 invece di 4 (domande 3 e 7).

## Decisioni aperte (di Marco)

- **Email autore dei commit:** è quella di lavoro (`m.patti@futuretechlead.com`). Non è stata
  cambiata nella riscrittura del 5-11 perché non era stato chiesto: cambiarla ora richiede un altro
  force push.

## Promemoria

- `backend/.env` contiene la chiave OpenAI vera; `INTERNAL_API_KEY` coincide con
  `STAPPLY_BACKEND_KEY` di `frontend/.env`.
- `backend/data` contiene i dati demo indicizzati: i test usano cartelle temporanee e non li
  toccano.
- Backend in sviluppo: `cd backend && uv run uvicorn app.main:app --port 8001`. Frontend:
  http://stapply.test (Herd).
- Pulizia delle sandbox scadute: `php artisan stapply:prune-demo`. Herd non esegue cron, per questo
  c'è anche una pulizia pigra a ogni accesso demo.
- I risultati completi della revisione (problemi, verdetti, riepiloghi) stanno nel journal del
  workflow `wf_1bd5b78b-f87`, nella cartella del progetto sotto `~/.claude/projects/`.
