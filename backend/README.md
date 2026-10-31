# stAPPly — backend (FastAPI)

Il servizio che *pinza* i documenti: li indicizza, tiene le tabelle in SQLite e fa rispondere
gli agenti con l'Agents SDK di OpenAI, citando i passaggi da cui viene ogni risposta.
Lo chiama solo Laravel (`frontend/`), che è la fonte di verità per utenti, agenti e istruzioni:
il backend non conosce gli utenti e lavora per `agent_id`.

Il contratto API (endpoint, regole sugli id, codici di errore, esempi JSON) è in
[`docs/api.md`](../docs/api.md) ed è l'unico riferimento: i test controllano che ogni risposta
abbia la forma degli esempi in [`docs/contract/`](../docs/contract/).

## Avvio

Serve [uv](https://docs.astral.sh/uv/) (Python 3.12 lo installa lui).

```bash
cd backend
uv sync
cp .env.example .env        # OPENAI_API_KEY e INTERNAL_API_KEY (la stessa di STAPPLY_BACKEND_KEY in Laravel)
uv run uvicorn app.main:app --reload --port 8001
```

Con un solo worker: il blocco che serializza gli upload di un agente vale dentro un processo.
`GET /health` risponde senza chiave; la documentazione interattiva è su <http://127.0.0.1:8001/docs>.

I log dell'app (`app.*`: upload, embedding, copie, errori della chat) escono a livello INFO nel
terminale di uvicorn, nel suo stesso formato (`INFO:     app.core.ingest: …`). `create_app()`
mette un handler solo sul logger `app`, e solo se nessuno ha già configurato il logging: le
librerie (httpx, chromadb) restano zitte e le righe di uvicorn non si duplicano.

### Agenti demo

```bash
uv run python -m scripts.seed_demo
```

Indicizza `seed/valtesa` e `seed/valdoria` (i temi del corso: documenti e tabelle finti) come
`demo-valtesa` e `demo-valdoria`, gli agenti dell'utente demo del frontend. È idempotente: prima
cancella tutto quello che il backend sa dei due agenti, conversazioni comprese, poi ricarica.
Senza `OPENAI_API_KEY` si ferma prima di toccare i dati.

## Test e lint

```bash
uv run pytest -q
uv run ruff check && uv run ruff format --check
```

I test non usano mai la rete (un fixture blocca i socket): gli embedding sono finti
(bag of words deterministico) e il modello è `tests/modello_finto.py`, un `Model` dell'SDK che
risponde con passi scritti a mano e registra cosa riceve (istruzioni, tool, memoria). Ogni test
ha la sua `DATA_DIR` in una cartella temporanea e `OPENAI_API_KEY` impostata a un valore finto
nell'ambiente, che vince sul `.env`: la chiave vera di `backend/.env` non arriva mai a un test,
nemmeno a uno che costruisce da sé un client OpenAI (`test_config.py` lo verifica).

## Variabili d'ambiente

Lette da `backend/.env` con pydantic-settings; una variabile d'ambiente vince sul file.

| Variabile | Default | A cosa serve |
|---|---|---|
| `OPENAI_API_KEY` | — | embedding e modello; senza (o se OpenAI la rifiuta) upload di documenti e chat rispondono `502`, con la causa solo nel log |
| `INTERNAL_API_KEY` | — | chiave dell'header `X-Internal-Key`; vuota = ogni richiesta ha `401` |
| `DATA_DIR` | `data` | dove vivono vettori, tabelle, file e sessioni (relativa a `backend/`) |
| `MODEL` | `gpt-5.6-luna` | il modello dell'agente |
| `EMBEDDING_MODEL` | `text-embedding-3-small` | cambiarlo richiede di reindicizzare i documenti |
| `EMBEDDING_BATCH` | `100` | testi per chiamata all'API degli embedding |
| `CHUNK_SIZE` | `500` | caratteri per chunk |
| `TOP_K` | `4` | passaggi restituiti da `cerca_documenti` |
| `MAX_TURNS` | `10` | passaggi massimi dell'agente in un turno di chat |
| `SESSION_HISTORY_TURNS` | `10` | turni precedenti della conversazione che il modello rilegge a ogni messaggio (almeno 1) |
| `MAX_UPLOAD_MB` | `10` | dimensione massima di un file |
| `MAX_CHUNKS_PER_DOCUMENT` | `2000` | chunk al massimo per documento (circa un milione di caratteri): oltre, `413` prima di qualunque embedding |
| `MAX_DOCUMENTS_PER_AGENT` | `20` | documenti per agente |
| `MAX_TABLES_PER_AGENT` | `10` | tabelle per agente |
| `QUERY_DB_LIMIT` | `50` | righe che `query_db` restituisce al modello |
| `SQL_CONSOLE_LIMIT` | `200` | righe che `POST /sql` restituisce alla console del frontend |
| `OPENAI_AGENTS_DISABLE_TRACING` | `true` | `false` per mandare le tracce dell'Agents SDK alla dashboard OpenAI (vedi [Privacy](#privacy-le-tracce-sono-spente)) |

## Struttura

```
app/
├── main.py              create_app(): router, chiave interna, gestori degli errori, /health
├── config.py            impostazioni (pydantic-settings), lette sempre al momento dell'uso
├── api/                 lo strato HTTP, sottile: valida e chiama il core
│   ├── dipendenze.py    X-Internal-Key, regex degli id nei percorsi, upload letto a pezzi
│   ├── schemi.py        corpi di richiesta e risposta di docs/api.md (Pydantic)
│   ├── errori.py        eccezioni di dominio → 404/409/413/415/422/502, 404/405 di Starlette
│   │                    in italiano, 500 senza dettagli
│   ├── documents.py     POST/GET /documents, DELETE /documents/{document_id}
│   ├── tables.py        POST/GET /tables, DELETE /tables/{table}, POST /sql
│   ├── chat.py          POST /chat, DELETE /sessions/{session_id}
│   └── agents.py        POST /clone, DELETE /agents/{agent_id}
└── core/                indipendente da FastAPI (lo usa anche scripts/seed_demo.py)
    ├── loaders/         dal corso (aikit): load(path) per .txt .md .pdf .docx .html, con
    │                    le tabelle dei .docx
    ├── clean.py         dal corso (aikit): gli step di pulizia; le ricette dei file degli
    │                    utenti sono in ingest.py
    ├── errors.py        errori di dominio, con il messaggio per l'utente in italiano
    ├── ids.py           regole di docs/api.md su id, nomi di file, tabelle e colonne
    ├── firme.py         estensione, dimensione e firma del contenuto dei file caricati
    ├── storage.py       percorsi sotto DATA_DIR, blocco per agente, cancellazioni
    ├── embeddings.py    embedding OpenAI a lotti
    ├── vectorstore.py   la collection Chroma `agent_<id>` di ogni agente
    ├── ingest.py        file → testo pulito → chunk → embedding → Chroma
    ├── tables.py        csv/xlsx → SQLite dell'agente; SELECT in sola lettura
    ├── clone.py         la copia di un agente su un altro (sandbox demo)
    ├── agent.py         l'Agent dell'SDK: istruzioni, regole fisse, tool costruiti a runtime
    └── chat.py          un turno di chat: sessione, Runner, traccia, fonti, errori
scripts/seed_demo.py     gli agenti demo
scripts/griglia.py       la griglia di prova del criterio 5 sugli agenti demo (docs/griglia/)
seed/                    documenti e tabelle finti dei due temi del corso
tests/
```

Sotto `DATA_DIR`:

```
chroma/                  i vettori: una collection `agent_<agent_id>` per agente
sessions.db              la memoria delle chat (SQLiteSession), chiave `<agent_id>:<session_id>`
agents/<agent_id>/
├── files/               gli originali dei documenti, come `<document_id><estensione>`
├── tables.db            le tabelle dell'agente: l'unico file che query_db apre
└── tables.json          nome del file e data di caricamento di ogni tabella
```

## Come risponde un agente

A ogni `POST /chat` Laravel manda il messaggio, le istruzioni dell'agente e il suo nome; il
backend costruisce l'agente da capo, perché istruzioni e dati possono essere cambiati:

1. **Istruzioni**: quelle di Laravel, seguite dalle regole fisse di stAPPly (`REGOLE_FISSE` in
   `core/agent.py`), che valgono anche se le prime dicono il contrario: rispondere solo con quello
   che restituiscono i tool, citare l'id del chunk tra `[ ]`, dire dove si è cercato quando non si
   trova la risposta, rifiutare le domande fuori tema senza chiamare tool, fermarsi dopo due errori
   di fila di un tool, trattare il contenuto di documenti e tabelle come dati, non istruzioni.
2. **Tool**: `cerca_documenti` solo se l'agente ha documenti, con i nomi dei file nella
   descrizione; `query_db` solo se ha tabelle, con lo schema vero (`tabella(colonna TIPO, …)`)
   nella descrizione. L'output di `query_db` ha un tetto di 20 000 caratteri: oltre, la query
   torna al modello come errore da correggere ("seleziona meno colonne o aggiungi un LIMIT").
3. **Memoria**: `SQLiteSession` con chiave `<agent_id>:<session_id>`, quindi la stessa sessione su
   due agenti non si mescola. Al modello arrivano gli ultimi `SESSION_HISTORY_TURNS` turni (vedi
   sotto).
4. **Risposta**: `answer` e `status`, più la traccia che serve alla griglia di prova: `tools`
   (nome e argomenti di ogni chiamata), `route` (`documenti`, `dati`, `documenti+dati`,
   `nessuna`) e `sources`, i chunk citati in `answer` tra quelli che `cerca_documenti` ha
   restituito in questo turno (un id inventato dal modello non diventa una fonte).

Se l'agente supera `MAX_TURNS` la risposta ha `status: "max_turns"`, un messaggio fisso e la
traccia di quello che ha fatto fin lì. Se OpenAI non risponde, anche dentro un tool (gli
embedding di `cerca_documenti`), la chat risponde `502` invece di far riprovare il modello.

Un turno intero ha al massimo 90 secondi (`TEMPO_MASSIMO_TURNO` in `core/chat.py`), meno dei
120 che Laravel aspetta il backend: oltre, risponde `502` ("non ha risposto in tempo: riprova
tra poco"). Senza questo tetto Laravel mostrerebbe il suo errore mentre il backend continua,
paga i token e salva nella sessione una risposta che l'utente non ha mai visto. Ogni chiamata al
modello ha un timeout di 60 s ed è ripetuta al massimo una volta (gli SDK di OpenAI ne fanno due
di default); lo stesso per gli embedding, con 30 s.
Se la chiave manca o OpenAI la rifiuta (`AuthenticationError`, `PermissionDeniedError`) il `502`
ha un messaggio generico ("Il modello non è disponibile in questo momento…"): chi usa la chat
non può farci niente e non deve sapere come è configurato il backend. La causa vera va nel log
a livello ERROR, dove la legge chi gestisce il servizio.

### Memoria: gli ultimi turni, tagliati prima di una domanda

Senza un limite ogni messaggio rimanderebbe al modello tutta la conversazione, output dei tool
compresi: il costo di un turno cresce con la chat, finché supera la finestra di contesto e da lì
la sessione risponde solo `502`. In `sessions.db` la storia resta intera; al modello ne arrivano
gli ultimi `SESSION_HISTORY_TURNS` turni.

Il taglio non usa il limite dell'SDK (`SessionSettings(limit=N)`), che conta gli item: con
l'SDK 0.22.3 potrebbe cadere tra un `reasoning` e la `function_call` o il `message` che lo segue,
e quella dipendenza l'SDK non la ripara (ripara solo un `function_call_output` rimasto senza la
sua chiamata, o il contrario). `chat.ultimi_turni` è un `RunConfig.session_input_callback` che
taglia solo prima di un messaggio `user`, cioè all'inizio di un turno: un turno arriva sempre
intero, con le sue chiamate, i loro output e il ragionamento che le precede.

## Copia di un agente (sandbox demo)

`POST /agents/{agent_id}/clone` con `{"from": "demo-valtesa"}` copia in `agent_id` documenti e
tabelle di un altro agente: ogni visitatore della demo riceve la sua copia degli agenti demo e
può cambiarla senza toccare quella degli altri. `core/clone.py`:

1. **Controlli**: `from` diverso da `agent_id` (`422`); `from` con almeno un documento o una
   tabella (`404`); `agent_id` senza documenti né tabelle (`409`, la copia non sovrascrive).
   Una collection rimasta vuota o un `tables.db` senza tabelle contano come "nessun dato".
2. **Blocchi**: quelli di tutti e due gli agenti, presi sempre in ordine alfabetico, così due
   copie incrociate (a da b, b da a) non si bloccano a vicenda.
3. **Vettori**: la collection della sorgente si legge a pagine (`get` con embedding, testi e
   metadati) e si scrive con `upsert` nella collection nuova: nessun embedding calcolato,
   quindi nessun costo, e gli id dei chunk restano quelli della sorgente, come le citazioni.
   I vettori coincidono a meno dell'ultima cifra di un float32: Chroma li rinormalizza per la
   metrica coseno a ogni inserimento.
4. **File**: gli originali dei documenti; `tables.db` con l'API di backup di SQLite (una
   fotografia coerente, non la copia di un file che potrebbe essere aperto); `tables.json`.
5. **Fallimento a metà**: la destinazione torna vuota (collection, originali, `tables.db`,
   `tables.json`). Le sue conversazioni restano, e quelle della sorgente non si copiano mai.

## Documenti: pulizia e limiti

Il testo che esce dai loader si normalizza (Unicode NFC, caratteri di controllo, spazi) e basta:
le ricette di `clean.py` del corso erano pensate per un libro di Project Gutenberg, un paper e
una pagina di Wikipedia, e sui file degli utenti toglievano testo vero (un HTML si fermava alla
prima riga "Note", anche l'intestazione di una colonna; un `.txt` perdeva quello fuori da
"\*\*\* START"/"\*\*\* END").

- **PDF**: pypdf estrae le tabelle una cella per riga, quindi le righe ripetute o fatte di sole
  cifre non si tolgono dal documento intero, o sparirebbero le celle ("Ordinaria / 5 / Studenti /
  8"). `_senza_testate` guarda solo i bordi di ogni pagina (le prime e le ultime tre righe non
  vuote) e toglie una riga se la stessa riga, nella stessa posizione, c'è in almeno metà delle
  pagine (e in almeno tre): testate e piè di pagina. Un numero conta come "numero meno numero
  della pagina", così il numero di pagina si ripete e una cella numerica no. Poi si riuniscono le
  parole spezzate a fine riga.
- **Word**: paragrafi e tabelle nell'ordine del documento, una riga per riga di tabella con le
  celle separate da ` | ` (una cella unita una volta sola).
- **Limite**: più di `MAX_CHUNKS_PER_DOCUMENT` chunk è un `413`, controllato prima degli
  embedding. Un `.docx` di poche centinaia di KB si può gonfiare fino a 100 MB di testo: senza
  limite sarebbero centinaia di migliaia di embedding a pagamento e gigabyte di vettori in memoria.

## Tabelle: CSV ed Excel

Ogni file diventa una tabella del `tables.db` dell'agente (un `.xlsx`: il primo foglio).

- **Nomi**: tabelle e colonne in minuscolo, **senza accenti** (`Città.csv` → `citta`,
  `Quantità` → `quantita`), il resto fuori da `[a-z0-9_]` diventa `_`, `t_` davanti a una cifra
  o a `sqlite_` (nomi riservati di SQLite). Le intestazioni italiane hanno spesso accenti, e il
  modello deve scrivere i nomi nelle query senza sbagliarli.
- **Tipi**: `INTEGER` se tutti i valori non vuoti di una colonna sono interi, `REAL` se sono
  tutti numeri, altrimenti `TEXT`. Un codice con zeri iniziali (`007`, un CAP) resta testo.
- **Separatore**: virgola o punto e virgola, scelto sui primi 20 record (non righe: un'intestazione
  tra virgolette con un a capo non inganna). Vince quello che dà a tutti lo stesso numero di
  campi, più di uno; poi quello che divide di più l'intestazione; poi quello coerente anche con
  un campo solo (una colonna di numeri come `1,5`); a parità il punto e virgola, se c'è nel testo
  (`Cognome, nome;Importo`).
- **Codifica**: UTF-8, oppure Windows-1252, come Excel su Windows salva i CSV se non si sceglie
  "CSV UTF-8".
- **Numeri all'italiana**: con il punto e virgola, il separatore che Excel usa dove la virgola
  serve ai decimali, i numeri scritti all'italiana (`4,50`, `1.234,50`, `-12,3`) si convertono
  prima dell'`INSERT`, ma solo nelle colonne in cui lo sono **tutti** i valori non vuoti: una
  colonna si legge tutta in un modo (`1.234` accanto a `4,50` è 1234, accanto a `12.5` è 1,234).
  Date come `15.07.2026` e codici come `007` restano testo. Con la virgola come separatore non
  cambia niente.
- **Excel**: openpyxl in sola lettura si fida del tag `<dimension>` del foglio, che alcuni
  generatori scrivono sbagliato (righe perse, o righe allungate a 16 384 colonne): si ricalcola
  sulle celle vere.
- **Limiti**: al massimo 500 colonne (`422`) e 2 milioni di celle, righe × colonne (`413`),
  controllati mentre il file si legge e prima di allungare le righe corte fino all'intestazione.

## Decisioni

- **Una collection Chroma per agente** (`agent_<id>`, metrica coseno) invece di una collection
  unica filtrata sui metadati: l'isolamento tra agenti non dipende da un filtro da non dimenticare,
  e cancellare un agente è cancellare la sua collection. Con migliaia di agenti si può rivalutare.
- **Un SQLite per agente** per le tabelle: `query_db` apre solo il file di quell'agente, e i
  metadati (nome del file, data) stanno fuori, in `tables.json`, così il modello vede solo i dati.
- **Chroma come unica fonte** per l'elenco dei documenti: si ricostruisce dai metadati dei chunk
  (`source`, `document_id`), senza un indice a parte da tenere allineato.
- **Ordine dell'upload**: prima tutto quello che può fallire (controlli, lettura, embedding), poi la
  sostituzione della versione precedente. Un file sbagliato non cancella mai quello che c'era.
- **Laravel tiene le istruzioni**, il backend aggiunge solo le regole fisse: un solo posto dove
  si modificano, e il backend resta senza stato sugli agenti.
- **Core indipendente da FastAPI**: `app/core` non importa nulla dell'API, così potrà essere
  esposto via MCP o usato da altri agenti dell'SDK (modulo 4) senza riscritture.

### Privacy: le tracce sono spente

L'Agents SDK manda di default ogni run alla dashboard Traces di OpenAI, con le domande, i
passaggi dei documenti restituiti da `cerca_documenti` e le righe di `query_db`. Per documenti
caricati dagli utenti non va bene, quindi `OPENAI_AGENTS_DISABLE_TRACING` vale `true` anche se
manca dal `.env`. Per studiare un run con dati di prova: `OPENAI_AGENTS_DISABLE_TRACING=false`
nel `.env`, riavvio di uvicorn, e le tracce compaiono su <https://platform.openai.com/traces>
raggruppate per sessione (`group_id` = `<agent_id>:<session_id>`).

## Sicurezza

- Ogni endpoint tranne `/health` vuole `X-Internal-Key`, confrontata con `secrets.compare_digest`;
  con `INTERNAL_API_KEY` vuota il servizio rifiuta tutto invece di accettare tutto.
- Gli id che arrivano negli URL passano le regex di `docs/api.md` prima di diventare percorsi o
  nomi di collection; i nomi dei file caricati sono ridotti al nome, senza cartelle.
- Upload: letto a pezzi fermandosi oltre `MAX_UPLOAD_MB` (`413`), limiti di numero per agente
  (`409`), firma del contenuto controllata oltre all'estensione (`415`), zip bomb nei `.docx`/`.xlsx`
  fermate prima di aprirle; poi limiti su quello che il file diventa una volta letto (chunk per
  documento, colonne e celle per tabella).
- Upload e cancellazione: un upload legge all'inizio quante volte l'agente è stato cancellato e
  lo ricontrolla sotto il blocco, prima di scrivere; se nel frattempo è arrivata la `DELETE`
  risponde `404` invece di ricreare collection o `tables.db` di un agente che per Laravel non
  esiste più.
- `query_db` e `/sql`: solo `SELECT`/`WITH`, una sola istruzione, connessione `mode=ro`,
  `PRAGMA query_only`, un authorizer SQLite che ammette solo letture, un limite di tempo e uno di
  dimensione: 500 colonne, 200 KB per valore e un tetto sul risultato intero (1 milione di
  caratteri per la console, 20 000 per `query_db`). `zeroblob()` produce un megabyte con una sola
  istruzione: il limite di tempo da solo non lo ferma.
- Prompt injection: i passaggi arrivano al modello tra delimitatori, con l'id e il file di
  provenienza; i nomi dei file, che sceglie chi li carica, arrivano tra virgolette JSON; le regole
  fisse dicono che nomi dei file e testi dei tool sono dati, non istruzioni.
- Gli errori di dominio hanno un messaggio in italiano pensato per l'utente; un errore imprevisto
  risponde `500` con un messaggio generico, e il traceback resta nel log del server. Anche
  i `404` di una rotta inesistente e i `405` di un metodo non ammesso hanno il `detail` in
  italiano. Una chiave OpenAI mancante o rifiutata non si racconta all'utente (vedi sopra).
