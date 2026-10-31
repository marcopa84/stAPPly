# stAPPly — dove siamo e cosa resta

Aggiornato il 1-11-2026.

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
  indipendente e sono tutti corretti nel codice, con un test ciascuno, **tranne `repo-01`** (sotto).
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

## Da fare

1. **Prova della sandbox nel browser**, quando Chrome è collegato, facendo prima logout dal
   template (la prova con curl è già passata).
2. **Idea, da decidere:** dare all'agente la data di oggi nelle regole fisse del backend. Oggi
   non sa che un prestito scaduto il 18 ottobre è scaduto (domanda 7 di Valdoria). È una modifica
   al comportamento: dopo, vanno rilanciate tutte e due le griglie.

## Decisioni aperte (di Marco)

- **`repo-01`, storia git:** il commit «backend: agente con regole fisse, chat con sessione e API»
  importa `app.core.clone`, che arriva solo nel commit dopo («backend: clone degli agenti per la
  sandbox demo»). In più il contratto di `/clone` sta nel commit «Contratto API», separato da
  backend e frontend, contro la regola di CLAUDE.md. Sistemarlo vuol dire riscrivere la storia
  già su GitHub e fare un force push: lo si fa solo con la tua conferma.
- **Docker:** il consiglio resta di non usarlo per lo sviluppo e di aggiungere più avanti un
  `docker-compose.yml` opzionale, per chi valuta il progetto o per il deploy.
- **Email autore dei commit:** è quella di lavoro (`m.patti@futuretechlead.com`). Cambiarla sui
  commit già pubblicati richiede un force push.
- **CLAUDE.md pubblico su GitHub:** contiene percorsi locali e istruzioni per l'agente. Tenerlo o
  toglierlo?

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
