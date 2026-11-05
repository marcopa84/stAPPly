# Contratto API tra frontend e backend

Laravel (`frontend/`) è l'unico client del backend FastAPI (`backend/`). Questo file e gli esempi
in [`contract/`](contract/) sono il contratto: i test del backend controllano che le risposte
abbiano la forma degli esempi, i test del frontend li usano come risposte finte (`Http::fake`).
Una modifica al contratto si fa nello stesso commit qui, nel backend e nel frontend.

## Regole comuni

- **Autenticazione.** Ogni richiesta, tranne `GET /health`, porta l'header `X-Internal-Key` con
  la chiave condivisa (`INTERNAL_API_KEY` nel backend, `STAPPLY_BACKEND_KEY` in Laravel).
  Chiave mancante o sbagliata: `401`.
- **Identificatori.**
  - `agent_id`: `^[a-z0-9](?:[a-z0-9-]{0,48}[a-z0-9])?$` (minuscole, cifre e trattini, 1–50
    caratteri, niente trattino ai bordi). Minuscole perché il file system di macOS non distingue
    maiuscole e minuscole e perché la collection Chroma `agent_<id>` deve finire con una lettera
    o una cifra. Laravel genera un ULID in minuscolo; gli agenti demo sono `demo-valtesa` e
    `demo-valdoria`.
  - `session_id`: `^[a-zA-Z0-9_-]{1,64}$`. In SQLite la sessione è `<agent_id>:<session_id>`.
  - `document_id`: il nome del file senza estensione, in minuscolo, con accenti tolti, ogni
    sequenza di caratteri fuori da `[a-z0-9]` sostituita da un trattino, senza trattini ai bordi,
    al massimo 80 caratteri (`documento` se resta vuoto). `Regolamento Ferie (2026).pdf` →
    `regolamento-ferie-2026`. I chunk si chiamano `<document_id>-000`, `-001`, …
  - nome tabella: il nome del file senza estensione, in minuscolo, con accenti tolti, ogni
    sequenza fuori da `[a-z0-9_]` sostituita da `_`, al massimo 60 caratteri, prefisso `t_` se
    inizia con una cifra o con `sqlite_`. `Note spese.csv` → `note_spese`, `Città.csv` → `citta`.
    Le colonne seguono la stessa regola (`Quantità` → `quantita`; doppioni: `_2`, `_3`).
    Gli accenti si tolgono perché le intestazioni italiane li hanno spesso e il modello deve
    poter scrivere i nomi nelle query senza sbagliarli.
- **Sostituzione.** Un documento con lo stesso `document_id` di uno già caricato lo sostituisce
  (anche con un'altra estensione); una tabella con lo stesso nome la sostituisce. La risposta
  lo dice con `"replaced": true`.
- **Errori.** Corpo `{"detail": "<messaggio in italiano, mostrabile all'utente>"}`. Fanno
  eccezione i `422` di validazione della richiesta (formato FastAPI: `detail` è una lista di
  oggetti): il frontend mostra il messaggio se `detail` è una stringa, altrimenti un messaggio
  generico.

| Codice | Quando |
|---|---|
| `401` | `X-Internal-Key` mancante o sbagliata |
| `404` | documento, tabella, (per `/sql`) tabelle dell'agente o (per `/clone`) dati dell'agente da copiare inesistenti |
| `409` | limite raggiunto: documenti (`MAX_DOCUMENTS_PER_AGENT`, 20) o tabelle (`MAX_TABLES_PER_AGENT`, 10), salvo sostituzione; per `/clone`, agente di destinazione che ha già dati |
| `413` | file oltre `MAX_UPLOAD_MB` (10) |
| `415` | estensione non ammessa, o contenuto che non corrisponde all'estensione (firma del file) |
| `422` | richiesta non valida, file illeggibile o senza testo, tabella vuota o malformata, query SQL rifiutata o in errore |
| `502` | il provider del modello (OpenAI) non risponde o rifiuta la chiamata |

Un agente "esiste" per il backend appena ha dati: le liste di un agente mai visto sono vuote, non `404`.

## Endpoint

| Metodo | Percorso | Richiesta | Risposta | Esempio |
|---|---|---|---|---|
| GET | `/health` | — | `200` | [`health.200.json`](contract/health.200.json) |
| POST | `/agents/{agent_id}/documents` | multipart `file` (.txt .md .pdf .docx .html, .htm come .html) | `201` | [`documents.upload.201.json`](contract/documents.upload.201.json) |
| GET | `/agents/{agent_id}/documents` | — | `200`, ordinati per `filename` | [`documents.list.200.json`](contract/documents.list.200.json) |
| DELETE | `/agents/{agent_id}/documents/{document_id}` | — | `204` | — |
| POST | `/agents/{agent_id}/tables` | multipart `file` (.csv .xlsx, primo foglio) | `201` | [`tables.upload.201.json`](contract/tables.upload.201.json) |
| GET | `/agents/{agent_id}/tables` | — | `200`, ordinate per `table` | [`tables.list.200.json`](contract/tables.list.200.json) |
| DELETE | `/agents/{agent_id}/tables/{table}` | — | `204` | — |
| POST | `/agents/{agent_id}/sql` | `{"sql": "SELECT …"}` | `200`, al massimo 200 righe | [`sql.200.json`](contract/sql.200.json) |
| POST | `/agents/{agent_id}/chat` | vedi sotto | `200` | [`chat.200.json`](contract/chat.200.json), [`chat.max-turns.200.json`](contract/chat.max-turns.200.json) |
| DELETE | `/agents/{agent_id}/sessions/{session_id}` | — | `204`, anche se la sessione non c'era | — |
| POST | `/titles` | `{"message": "…", "answer": "…"}` | `200` | [`titles.200.json`](contract/titles.200.json) |
| POST | `/agents/{agent_id}/clone` | `{"from": "<agent_id da copiare>"}` | `201` | [`agents.clone.201.json`](contract/agents.clone.201.json) |
| DELETE | `/agents/{agent_id}` | — | `204`, anche se l'agente non aveva dati | — |
| — | qualunque errore | — | `4xx`/`5xx` | [`error.json`](contract/error.json) |

### `POST /agents/{agent_id}/sql`

La stessa esecuzione in sola lettura del tool `query_db` (solo `SELECT` o `WITH`, una sola
istruzione, connessione `mode=ro`), con un limite di righe più alto. Serve alla console SQL del
frontend per verificare a mano i numeri dell'agente (criterio 4 di `CONSEGNA.md`).
Query rifiutata o in errore: `422` con il motivo in `detail`. Agente senza tabelle: `404`.

### `POST /agents/{agent_id}/clone`

Copia in `agent_id` documenti e tabelle dell'agente `from`: vettori già calcolati (nessun
embedding nuovo, quindi niente costi), metadati, file originali, database delle tabelle. Le
sessioni di chat non si copiano. Gli id dei chunk restano quelli della sorgente, così le
citazioni sono identiche. Serve alla sandbox demo: ogni visitatore riceve la sua copia degli
agenti demo e può cambiarla senza toccare quella degli altri.

| Caso | Risposta |
|---|---|
| copia riuscita | `201` con il numero di documenti e tabelle copiati |
| `from` senza documenti né tabelle | `404` |
| `agent_id` ha già documenti o tabelle | `409`: la copia non sovrascrive |
| `from` uguale ad `agent_id`, o non conforme alla regex | `422` |

### `POST /titles`

Il titolo di una chat, scritto dal modello a partire dal primo scambio: Laravel apre la chat al
primo messaggio con un titolo provvisorio e, dopo la prima risposta, chiede questo. È una
chiamata senza tool e senza sessione, con un tempo massimo di 20 secondi; non dipende
dall'agente.

| Campo | Vincoli |
|---|---|
| `message` | 1–4000 caratteri: la prima domanda |
| `answer` | 1–50000 caratteri: la prima risposta (al modello ne arriva solo l'inizio) |

Risposta: `title`, da 2 a 6 parole in italiano, al massimo 60 caratteri, senza virgolette né
punto finale. Se il modello non risponde o non scrive un titolo: `502`, e Laravel tiene quello
provvisorio.

### `POST /agents/{agent_id}/chat`

Richiesta:

```json
{
  "session_id": "01jbx3k6v2c8m0q4w5t7y9z1ab",
  "message": "Qual è il massimale per una cena in trasferta a Milano?",
  "instructions": "Sei l'assistente dell'ufficio del personale di Valtesa S.r.l. …",
  "agent_name": "Assistente Valtesa"
}
```

| Campo | Vincoli |
|---|---|
| `session_id` | vedi sopra |
| `message` | 1–4000 caratteri |
| `instructions` | 0–8000 caratteri: le istruzioni scritte in Laravel. Il backend aggiunge le regole fisse di stAPPly |
| `agent_name` | 1–100 caratteri |

Risposta:

| Campo | Contenuto |
|---|---|
| `answer` | il testo della risposta (può contenere markdown e id di chunk tra `[ ]`) |
| `status` | `ok`, oppure `max_turns` se l'agente si è fermato dopo `MAX_TURNS` passaggi: allora `answer` è un messaggio fisso che lo dice |
| `route` | `documenti`, `dati`, `documenti+dati` o `nessuna`, calcolata dai tool chiamati |
| `tools` | i tool chiamati in questo turno, in ordine: `name` e `arguments` (l'oggetto JSON degli argomenti; se non è JSON valido, `{"_raw": "<testo>"}`) |
| `sources` | i chunk citati in `answer` (id tra `[ ]`) tra quelli restituiti da `cerca_documenti` in questo turno: `id`, `document_id`, `filename`, `text` |
