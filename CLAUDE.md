# stAPPly — contesto per il coding agent

Progetto finale del Master AI Engineering (Boolean), modulo 3 (RAG), e base per il modulo 4 (agenti).
È anche un progetto da portare ai colloqui di Marco: contano qualità del codice, test e un README chiaro.

## Nome, tagline, storytelling
- Si scrive sempre **stAPPly**, con "APP" evidenziato. Il nome è un gioco di parole voluto:
  *staple* (pinzare) + *apply* (applicare). La doppia "p" non è un refuso: va dichiarata in logo e README.
- Tagline: **"Staple your docs. Apply your knowledge."**
- Storia: nel 1997 l'assistente di Office interrompeva senza capire; stAPPly è il suo erede intelligente.
  La graffetta tiene i fogli insieme in modo provvisorio, la pinzatrice li unisce davvero: come il RAG
  unisce passaggi di documenti diversi in una risposta con le fonti.
- Mascotte: una **pinzatrice originale**, con personalità e stile propri. Non usare immagini, gif o nome
  di Clippy (marchio Microsoft, escluso anche dalle emoji open source di Microsoft) e non imitarne
  pose, espressioni o animazioni. Il riferimento resta nel racconto, a parole, non nel disegno.

## Decisioni
- Monorepo: `frontend/` Laravel + Livewire (Herd, niente Docker), `backend/` Python FastAPI.
- Laravel è la fonte di verità per utenti, agenti, permessi e **istruzioni degli agenti**: le salva e le
  passa al backend a ogni chat (`instructions`, `agent_name`). Il backend non conserva profili:
  aggiunge solo le regole fisse (sotto) e lavora per `agent_id`.
- Stack AI come nel corso: `openai-agents==0.22.3`, `SQLiteSession` per la memoria, ChromaDB,
  embedding espliciti `text-embedding-3-small`, chunk recursive da 500 caratteri, modello `gpt-5.6-luna`,
  ambiente con `uv`.
- **Una collection Chroma per agente** (`agent_<id>`, metrica cosine) invece di una collection unica
  filtrata sui metadati: isolamento tra agenti più semplice da garantire e cancellazione pulita
  (si cancella la collection). Con migliaia di agenti si potrà rivalutare.
- Tabelle: un SQLite per agente; sessioni di chat in un SQLite con chiave `<agent_id>:<session_id>`.
- Formati: documenti .txt .md .pdf .docx .html (max ~20 per agente); tabelle .csv .xlsx (una per file).
- Utente demo da seeder con agenti `demo-valtesa` e `demo-valdoria` (dati finti in `backend/seed/`);
  gli altri utenti caricano i propri file.
- Sicurezza minima: limite dimensione/numero file, controllo firma file oltre all'estensione,
  `query_db` solo SELECT su connessione `mode=ro`, documenti caricati trattati come dati (prompt injection).
- Regole fisse dell'agente: rispondere solo da quanto restituiscono i tool, citare l'id del chunk
  tra [ ], dire quando non trova la risposta e dove ha cercato, rifiutare le domande fuori tema
  senza chiamare tool, fermarsi se un tool sbaglia due volte di fila.

## Criteri di accettazione (dal corso)
Il progetto deve superare i "fatto quando" di `CONSEGNA.md` (percorso sotto), applicati a ogni agente:
1. chat con sessione: chiusa e riaperta con lo stesso nome, l'agente ricorda lo scambio;
2. una ricerca sulla collection restituisce chunk dei file, con il nome del file;
3. una domanda sui documenti cita un chunk; una fuori tema è rifiutata senza tool (visibile nella traccia);
4. con tabelle: la risposta su un dato coincide con una SELECT scritta a mano; una domanda che
   richiede entrambe le fonti chiama tutti e due i tool;
5. griglia di 8 domande (3 documenti, 3 dati, 1 mista, 1 senza risposta, più un follow-up),
   ognuna lanciata almeno due volte in sessioni nuove; correzioni una alla volta, poi si rilancia tutto.
Per questo la risposta della chat espone sempre `tools` (nome e argomenti) e `route`
(`documenti`, `dati`, `documenti+dati`, `nessuna`).

## Direzione futura (modulo 4)
Il core deve poter crescere con le lezioni successive senza riscritture: skill caricate su richiesta,
esposizione via MCP, più agenti nell'SDK (passaggi tra agenti), tool che esegue codice sotto approvazione.
Tenere il core (`app/core`) indipendente da FastAPI.

## Repository (monorepo)
- Una sola repo `stapply` con `frontend/` e `backend/`: un solo `.git`, in radice.
- Dopo `laravel new frontend` verificare che non esista `frontend/.git`; se c'è va rimossa,
  altrimenti GitHub tratta `frontend` come un sottomodulo vuoto.
- `.gitignore` per cartella: Laravel ha il suo in `frontend/`, il backend il suo in `backend/`,
  quello in radice copre solo file di sistema ed editor. `backend/.env` non va mai tracciato.
- Una modifica al contratto API si fa nello stesso commit su backend e frontend.
- CI: workflow GitHub Actions separati, filtrati per percorso (`frontend/**`, `backend/**`);
  i due servizi si deployano in modo indipendente.

## Da dove prendere il codice
Corso: `~/Desktop/BOOLEAN/AI/ai-engineering-master-shared-material/` (branch `main`; le soluzioni
complete stanno sul branch `soluzioni`). Lab di riferimento:
`modulo3-rag/l12_lab-assistente-propri-dati/scripts/` (sotto: `SCRIPTS`).
In `SCRIPTS/MAPPA.md` c'è la mappa delle lezioni da riaprire per ogni parte.

| Pezzo di stAPPly | Da prendere | Cosa cambiare |
|---|---|---|
| `app/core/loaders/` | `SCRIPTS/scaffolding/aikit/loaders/` | **già copiati**; `load(path)` smista per estensione |
| `app/core/clean.py` | `SCRIPTS/scaffolding/aikit/clean.py` | **già copiato**; `clean(testo, pipeline_for(path))` |
| chunking in `ingest.py` | `chunk_recursive` in `SCRIPTS/scaffolding/aikit/chunk.py` | solo quella funzione, senza le parti su `dataset/` |
| `app/core/embeddings.py` | `embed_openai` in `SCRIPTS/scaffolding/aikit/embeddings.py` | modello da config, invio a lotti, niente `print` |
| `app/core/vectorstore.py` | `apri_collection`, `crea_collection`, `indicizza`, `search` in `SCRIPTS/scaffolding/aikit/vectorstore.py` | `CHROMA_DIR` da config invece del percorso fisso; collection `agent_<id>`; cancellare i chunk di un documento con `where={"document_id": ...}` |
| `app/core/ingest.py` | ciclo di `SCRIPTS/solutions/obiettivo2_documenti.py` | da cartella a singolo file caricato; id chunk `<documento>-000`; metadata `source` e `document_id`; stesso nome file sostituisce |
| `app/core/tables.py` | `tipo()` e `salva()` di `SCRIPTS/scaffolding/tabelle_in_db.py` | da script con `sys.argv` a funzioni su un file caricato; nomi tabella sanificati |
| `cerca_documenti`, `query_db` in `agent.py` | `SCRIPTS/solutions/obiettivo4_assistente.py` | collection e DB dell'agente invece di `TEMA`; descrizione di `query_db` generata dallo schema reale delle tabelle; `query_db` solo se l'agente ha tabelle |
| istruzioni e traccia in `agent.py` | `ISTRUZIONI`, `tool_chiamati()`, `strada()` dello stesso file | istruzioni = quelle di Laravel + le regole fisse sopra |
| `app/api/chat.py` | ciclo `__main__` dello stesso file (`Runner.run_sync` + `SQLiteSession`) | `await Runner.run(...)` in FastAPI; gestire `MaxTurnsExceeded`; risposta `{answer, tools, route}` |
| miglioramenti retrieval (dopo) | `SCRIPTS/scaffolding/aikit/hybrid.py`, `rerank.py` | BM25 + rerank, solo se la griglia mostra errori di retrieval |
| prova e griglia | `SCRIPTS/CONSEGNA.md`, `SCRIPTS/working/griglia.md`, `SCRIPTS/solutions/griglia.md` | stesso formato per le 8 domande dei due agenti demo |

Da verificare, non dare per scontato: come l'SDK 0.22.3 permette una descrizione del tool costruita
a runtime (serve per lo schema di `query_db` per agente). Controllare firma di `function_tool`
o usare `FunctionTool` direttamente, prima di scrivere codice.
