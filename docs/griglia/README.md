# Griglia di prova

Il criterio 5 di [`CLAUDE.md`](../../CLAUDE.md) (la consegna del corso, Modulo 3 · Lezione 12)
applicato ai due agenti demo. Ci sono otto domande: tre sui documenti, tre sui dati, una che ha
bisogno di tutte e due le fonti e una senza risposta, più un follow-up. Ognuna si lancia almeno
due volte, ogni volta in una sessione nuova, e l'esito si segna accanto alla risposta vera,
cercata prima.

| File | Cosa contiene |
|---|---|
| [`valtesa.json`](valtesa.json) | le domande del docente sull'ufficio del personale (`demo-valtesa`), con le risposte vere ricontrollate sul seed |
| [`valdoria.json`](valdoria.json) | otto domande equivalenti più il follow-up sulla biblioteca (`demo-valdoria`) |
| `risultati-<tema>-<data>.md` | un giro della griglia, scritto da `backend/scripts/griglia.py` e compilato a mano; il primo nasce dal primo lancio con la chiave vera |

## Le domande e le risposte vere

Ogni domanda ha `tipo`, `domanda`, `risposta_vera`, `strada_attesa` e le `fonti`, cioè come è
stata trovata la risposta vera:

- un passaggio di un documento: `file`, `chunk` (l'id che l'agente dovrebbe citare) e
  `passaggio`, copiato dal testo indicizzato;
- una SELECT scritta a mano: `sql` e `risultato`, le righe che restituisce.

La domanda senza risposta ha invece `assenti`: parole che non compaiono in nessun documento né
tabella. Il follow-up ha `segue`, il numero della domanda nella cui sessione va lanciato.
`nota`, se c'è, spiega la trappola della domanda.

Le risposte vere non si fidano della memoria: `backend/tests/test_griglia.py` indicizza il seed
come fa `seed_demo.py` e controlla che ogni passaggio sia nel suo chunk, che ogni SELECT dia
proprio quelle righe sulle tabelle importate dal backend e che le parole `assenti` non compaiano
da nessuna parte. Se cambiano i file del seed o il modo di indicizzarli, il test lo dice. La CI
del backend parte anche quando cambiano questa cartella o `DemoSeeder.php`, quindi una cura alle
istruzioni o una risposta vera corretta passano dagli stessi controlli.

## Lanciarla

Servono la chiave OpenAI in `backend/.env` e gli agenti demo indicizzati. Da `backend/`:

```bash
uv run python -m scripts.seed_demo          # una volta, se gli agenti demo non ci sono ancora
uv run python -m scripts.griglia valtesa    # poi: uv run python -m scripts.griglia valdoria
```

Con `--lanci 3` ogni domanda si lancia tre volte, come nella griglia del docente (il default è
2). Ogni giro costa almeno una chiamata al modello per domanda e per lancio (18 con due lanci),
una in più dopo ogni chiamata dei tool, più gli embedding delle ricerche. Senza
`OPENAI_API_KEY` lo script esce subito, senza lanciare niente; se gli agenti demo non hanno
documenti o tabelle, chiede di lanciare prima `seed_demo`.

Lo script fa le domande con la stessa funzione del core che usa la chat (`chat.rispondi`) e con
le istruzioni che Laravel passa al backend, lette da
[`DemoSeeder.php`](../../frontend/database/seeders/DemoSeeder.php): le costanti
`VALTESA_INSTRUCTIONS` e `VALDORIA_INSTRUCTIONS` sono l'unica fonte, e se il loro formato cambia
lo script si ferma invece di usare un testo vecchio. Ogni lancio usa una sessione nuova,
`griglia-<data>-d<n>-l<lancio>`; il follow-up usa quella della sua domanda, subito dopo. Le
sessioni restano nel `sessions.db` del backend, non nelle chat dell'interfaccia, e `seed_demo`
le cancella insieme al resto quando riprepara gli agenti.

## Compilare l'esito

Il file dei risultati ha in testa la tabella della consegna, poi una sezione per domanda: la
risposta vera con le sue fonti e, per ogni lancio, strada, citazioni, tool con gli argomenti,
tempo e risposta. Sotto ogni lancio si scrive l'esito al posto di `…`:

- **giusta**: dice la risposta vera e cita il chunk giusto (o, per i dati, ha fatto una SELECT
  che dà quei numeri);
- **sbagliata**: un numero, una regola o una persona diversi dalla risposta vera, anche se
  citati;
- **«non trovato»**: dice di non aver trovato una risposta che c'era;
- **rifiuto giusto**: per la domanda senza risposta, dice di non averla trovata e dove ha
  cercato, senza inventare.

Poi nella tabella si scrive quante volte ogni domanda è giusta (per esempio `1/2`) e il totale.
La strada attesa aiuta a leggere la traccia: una risposta giusta arrivata dalla strada sbagliata
(un numero dai documenti invece che da `query_db`) va guardata con sospetto.

## La cura

Come nella consegna: si sceglie una domanda sbagliata, si cambia **una cosa sola** (una riga
delle istruzioni in `DemoSeeder.php`, oppure un'impostazione del backend come `TOP_K`), si
rilancia **tutta** la griglia e si confrontano i due file dei risultati. Ognuno riporta in fondo
le istruzioni usate e in testa modello e K, quindi due giri restano confrontabili. Se la cura
funziona e non rompe le altre domande, si riallinea Laravel con
`php artisan db:seed --class=DemoSeeder` (da `frontend/`).
