# stAPPly — dove siamo e cosa resta

Aggiornato il 31-10-2026, sera, prima di spegnere.

## Fatto

- **Progetto completo e committato** su `main`, già su GitHub (`git@github.com:marcopa84/stAPPly.git`),
  in 15 commit per argomento: contratto API, backend (ingestione, tabelle, agente, chat, API, clone,
  seed, griglia), frontend (starter kit, brand e mascotte, agenti, chat con traccia e console SQL,
  sandbox demo), CI e README.
- **Verificato dal vivo con la chiave vera** (`backend/.env`):
  - seed demo (236 chunk, 6 tabelle);
  - 6 domande della griglia del docente su Valtesa, tutte giuste: citazioni `[id]`, route corrette,
    rifiuto fuori tema senza tool, follow-up con memoria;
  - chat nel browser: `send()` → `reply()`, traccia, popover delle citazioni.
- **Sandbox demo:** ogni accesso con le credenziali demo crea un utente temporaneo con la sua copia
  degli agenti demo, clonata dal backend senza nuovi embedding. Le sandbox scadono e vengono pulite.
  Password, email, doppia autenticazione e passkey della demo sono bloccate.

## Lavoro interrotto, NON committato

Il workflow `stapply-sandbox-review` (run `wf_1bd5b78b-f87`) si è fermato durante le correzioni.

| Fase | Stato |
|---|---|
| Revisione | 46 problemi trovati, **42 confermati** da un verificatore avversariale, 4 respinti |
| Correzioni docs e CI | finite |
| Correzioni backend e frontend | **in corso allo spegnimento**. Quelle fatte fino al commit «correzioni dalla revisione, prima parte» sono committate, con le suite verdi in quel momento (backend 440 test, frontend 288). Quello che è arrivato dopo è nel working tree, anche a metà |
| Verifica finale | non eseguita |

Nel codice committato risultano già presenti (da ricontrollare con i test): `tab-01`, `ing-03`,
`chat-01`, `sec-01b`, `sec-02`, `sec-01`, `sec-07` (limite per turni, non per item), `fe-01`,
`fe-02`, `sec-03`, `sec-04`, `fe-04`, `sec-06`, `fe-11`, `fe-12`, `sec-09`, `sec-10`, `fe-14`.
La pulizia dei PDF è cambiata (`ing-01`): **rilanciare `seed_demo`**, perché i chunk in `backend/data`
sono stati indicizzati con la pulizia vecchia.

Tutti i risultati (problemi, verdetti, riepiloghi dei correttori) stanno nel journal:
`~/.claude/projects/-Users-marcopatti-Desktop-MarcoPa84-stapply/1d979f74-3a54-479f-b823-c9c3cb805549/subagents/workflows/wf_1bd5b78b-f87/journal.jsonl`

### Come riprendere

1. `git status` e `git diff --stat` per vedere cosa hanno toccato i correttori.
2. Lancia le suite e guarda cosa è rotto:
   - `cd backend && uv run pytest -q && uv run ruff check && uv run ruff format --check`
   - `cd frontend && php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G && npm run build`
   - `cd frontend && php artisan migrate` (ci sono due migration nuove)
3. Per ogni voce della checklist qui sotto, controlla nel diff se è già corretta; completa le altre.
   Ogni correzione va con un test che fallirebbe senza.
4. Verifica finale: suite e lint di entrambi i lati, i passi della CI su una copia pulita
   (`git ls-files`), `migrate --seed` su un DB nuovo, prova del clone con un backend su una porta
   tra 8020 e 8040 e `DATA_DIR` temporanea.
5. Commit per argomento, senza righe di attribuzione.

### Checklist dei problemi confermati

Legenda: ✅ corretto · 🟡 corretto in parte · ⬜ da controllare (correttore interrotto).

**Gravità alta**
- ⬜ `ing-01` backend · la pulizia dei PDF toglie le celle delle tabelle: numeri soli su una riga e valori ripetuti spariscono dai chunk, anche negli agenti demo. Fare una pulizia per pagina in `ingest`, senza toccare le ricette del corso in `clean.py`. Dopo: **rilanciare `seed_demo`**.
- ⬜ `ing-02` backend · le ricette del corso troncano l'HTML alla prima riga «Note» e il TXT tra «*** START» e «*** END». Per .html e .txt dei file utente usare `DEFAULT_STEPS`. Dopo: **rilanciare `seed_demo`**.
- ⬜ `sec-01` backend · una SELECT con blob grandi esaurisce la memoria: serve un tetto sulla dimensione del risultato in `esegui_select`.
- ⬜ `sec-02` backend · un .docx di 392 KB diventa 195.000 chunk: serve `max_chunks_per_document` prima degli embedding.
- ⬜ `tab-01` backend · un xlsx in read_only si fida del tag `<dimension>`: righe perse o memoria esplosa. Serve `reset_dimensions()`.
- ⬜ `fe-01` frontend · eliminando l'account, i dati nel backend restano: serve un servizio `AgentPurger` (il correttore l'aveva iniziato).
- ⬜ `fe-02` frontend · un'email con maiuscole salvata dal profilo chiude fuori l'utente: va salvata in minuscolo (il correttore l'aveva iniziato).

**Gravità media**
- ⬜ `chat-01` backend · i tempi non stanno sotto i 120 s di Laravel: `max_retries` del client OpenAI e un `asyncio.timeout` attorno a `Runner.run`.
- ⬜ `ing-03` backend · il loader .docx non legge le tabelle Word.
- ⬜ `sec-01b` backend · il 401 è testato su una sola rotta: serve un test parametrizzato su tutte le rotte di `openapi()`.
- ⬜ `sec-07` backend · la memoria della chat non ha limite. Attenzione: il backend aveva già scartato `SessionSettings.limit`, perché il taglio può separare un reasoning dalla sua function_call. Rivalutare, oppure documentare un limite diverso.
- ⬜ `tab-02` backend · il separatore dei CSV si sceglie solo dalla prima riga: valutare i primi ~20 record per ciascun separatore.
- ⬜ `tab-03` backend · `nomi_colonne` è cubico sulle intestazioni ripetute.
- ✅ `ci-01` / `ci-03` CI · la CI del backend parte anche su `docs/griglia/**` e `DemoSeeder.php`.
- ⬜ `fe-04` / `sec-05` frontend · una persona riempie da sola le 50 sandbox: limite per IP sulle sandbox attive e RateLimiter sui login demo.
- ⬜ `sec-03` frontend · `reply()` si può rilanciare scavalcando i limiti: serve una presa in carico atomica (`reply_started_at`, migration già creata dal correttore).
- ⬜ `sec-04` frontend · le immagini markdown nelle risposte si caricano da sole (esfiltrazione): renderle come testo (`ImageAsTextExtension` e `RestrictImageSources` iniziati).
- ⬜ `sec-06` frontend · gli utenti registrati non hanno limiti di costo (agenti, upload, chat al giorno): `UsageLimits` iniziato.

**Gravità bassa**
- ⬜ `ing-04` backend · un upload che si incrocia con `DELETE /agents/{id}` dà 500 e lascia un documento senza originale.
- ⬜ `lim-02` backend · manca il test del secondo controllo del limite documenti, quello sotto lock.
- ⬜ `sec-08` backend · i nomi dei file entrano nella descrizione del tool senza essere trattati come dati.
- ⬜ `tab-04` backend · i CSV di Excel italiano (cp1252) vengono rifiutati: almeno spiegarlo nel messaggio 415.
- ⬜ `test-08` backend · asserzione tautologica in `test_contratto`.
- 🟡 `ci-04` CI · il test del parser PHP veniva sempre saltato in CI. Parte CI fatta (setup-php + composer in `backend.yml`). Resta: in `test_griglia.py` mettere uno stub della classe Seeder al posto di vendor, poi togliere il passo composer.
- 🟡 `req-01` docs · il criterio 5 non è ancora dimostrato (vedi «Griglia vera» sotto). Aggiungere `scripts/griglia.py` alla struttura in `backend/README.md`.
- ⬜ `repo-01` git · il commit `32640f7` importa `app.core.clone`, che arriva solo con `65f1a38`. Il contratto di `/clone` (`3c9e491`) sta in un commit separato da backend e frontend, contro la regola di CLAUDE.md. **È già su GitHub: correggerlo vuol dire force push. Decidi tu.**
- ⬜ `fe-03` frontend · `abandon()` cancella solo in locale la copia fallita: usare `purge()` su tutte.
- ⬜ `fe-07` frontend · titoli delle impostazioni e due messaggi ancora in inglese (in parte fatto).
- ⬜ `fe-08` frontend · indici sulle chiavi esterne (migration già creata dal correttore).
- ⬜ `fe-09` frontend · codice morto dello starter kit (layout header, card e simple, icone): in parte cancellato.
- ⬜ `fe-11` frontend · se `reply()` fallisce a livello HTTP, manca il pulsante Riprova: `.catch(() => $wire.replyFailed())`.
- ⬜ `fe-12` frontend · i pulsanti «Prova» della console SQL hanno tutti lo stesso `aria-label`.
- ⬜ `fe-13` frontend · una sandbox già cancellata porta al login senza il messaggio «La tua demo è terminata».
- ⬜ `fe-14` frontend · la textarea della chat perde il focus: usare `readonly` invece di `disabled`.
- ⬜ `sec-09` frontend · un id «..» in `confirmDelete` fa cancellare tutto l'agente: rifiutare i segmenti `.` e `..` e usare `withoutRedirecting()`.
- ⬜ `sec-10` frontend · la registrazione rivela le email registrate e non ha limite di tentativi (`ThrottleRegistration` iniziato).
- ⬜ `sec-11` frontend · gli upload temporanei di Livewire restano 24 ore (`config/livewire.php` pubblicato).
- ⬜ `test-05` / `test-06` / `test-07` frontend · test d'esempio vuoti e `toBeOne` inutilizzato (già tolti), più un salvataggio non verificato in `DemoSandboxLifecycleTest`.

## Da fare dopo le correzioni

1. **Prova completa della sandbox nel browser.** Prima fai logout: Chrome è ancora collegato al template. Poi controlla:
   - due accessi demo danno due sandbox distinte, con cloni distinti;
   - una modifica in una sandbox non si vede nell'altra;
   - dopo logout e `php artisan stapply:prune-demo` i cloni spariscono dal backend;
   - `demo-valtesa` e `demo-valdoria` hanno ancora documenti e tabelle.
2. **Griglia vera, criterio 5** (servono la chiave e i dati demo; dopo `ing-01`/`ing-02` rilancia `seed_demo`):
   - `cd backend && uv run python -m scripts.griglia valtesa`, poi lo stesso con `valdoria`;
   - compila l'esito nei file `docs/griglia/risultati-*.md`;
   - applica una cura alla volta in `DemoSeeder.php` (per esempio la riga sugli omonimi, come la cura 1 del docente), rilancia tutte e due le griglie e poi `php artisan db:seed --class=DemoSeeder`;
   - committa i risultati prima e dopo la cura e scrivi il punteggio nella tabella dei criteri del README.
3. **CLAUDE.md:** aggiorna la riga «Utente demo da seeder…» con la sandbox (una copia degli agenti demo per ogni accesso, reset al prossimo login, istruzioni ufficiali in `DemoSeeder`).

## Decisioni aperte (tue)

- **Docker:** il mio consiglio resta di non usarlo per lo sviluppo e di aggiungere più avanti un `docker-compose.yml` opzionale per chi valuta il progetto o per il deploy.
- **Email autore dei commit:** oggi è quella di lavoro (`m.patti@futuretechlead.com`), mentre per un progetto personale forse vuoi `marcopa84@hotmail.it`. I commit sono già su GitHub, quindi cambiarla costa un force push.
- **CLAUDE.md pubblico su GitHub:** contiene percorsi locali e istruzioni per l'agente. Tenerlo o toglierlo dal repo?
- **`repo-01`:** riordinare i commit con un force push, oppure lasciare la storia com'è.

## Promemoria

- `backend/.env` contiene la chiave OpenAI vera; `INTERNAL_API_KEY` coincide con `STAPPLY_BACKEND_KEY` di `frontend/.env`.
- `backend/data` contiene i dati demo indicizzati: i test usano cartelle temporanee, non toccarli.
- Backend in sviluppo: `cd backend && uv run uvicorn app.main:app --port 8001`. Il frontend è su http://stapply.test (Herd).
- Pulizia delle sandbox scadute: `php artisan stapply:prune-demo`. Herd non esegue cron, quindi c'è anche una pulizia pigra a ogni accesso demo.
