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

## I giri fatti

| File | Giro | Esito |
|---|---|---|
| [`risultati-valtesa-20261031-145630.md`](risultati-valtesa-20261031-145630.md) | Valtesa, primo giro, 2 lanci | 18/18 |
| [`risultati-valdoria-20261031-145819.md`](risultati-valdoria-20261031-145819.md) | Valdoria, primo giro, 2 lanci | 17/18 |
| [`risultati-valdoria-20261031-150153.md`](risultati-valdoria-20261031-150153.md) | Valdoria dopo la cura 1, 3 lanci | 27/27 |
| [`risultati-valtesa-20261105-195716.md`](risultati-valtesa-20261105-195716.md) | Valtesa dopo la cura 2, 3 lanci | 27/27 |
| [`risultati-valdoria-20261105-195929.md`](risultati-valdoria-20261105-195929.md) | Valdoria dopo la cura 2, 3 lanci | 27/27 |
| [`risultati-valtesa-20261105-204302.md`](risultati-valtesa-20261105-204302.md) | Valtesa dopo la cura 3, 3 lanci | 25/27 |
| [`risultati-valtesa-20261105-204813.md`](risultati-valtesa-20261105-204813.md) | Valtesa dopo la cura 3, di nuovo, 3 lanci | 25/27 |
| [`risultati-valdoria-20261105-204539.md`](risultati-valdoria-20261105-204539.md) | Valdoria dopo la cura 3, 3 lanci | 27/27 |

### Cura 1: le risposte sì/no di Valdoria

**Cosa succedeva.** Alla domanda 7 di Valdoria («Il prestito 620 si può rinnovare ancora?»), al
secondo lancio, la risposta cominciava con «Sì.» e due righe dopo diceva che il prestito non è
rinnovabile: i dati erano giusti (2 rinnovi già fatti, massimo 2 per i libri), la risposta no.

**Cosa ho cambiato.** Una riga in fondo a `VALDORIA_INSTRUCTIONS`:

> A una domanda che chiede sì o no rispondi prima «Sì» o «No», e il resto della risposta deve
> dire la stessa cosa.

**Le altre dopo la modifica.** Rilanciata tutta la griglia di Valdoria, con tre lanci invece di
due: 27/27, la domanda 7 comincia sempre con «No». Le istruzioni di Valtesa non sono cambiate,
quindi la sua griglia non è stata rilanciata.

### Cura 2: i valori delle colonne di testo nello schema di `query_db`

**Cosa succedeva.** Provando l'app in Docker, alla domanda 5 di Valtesa («Quante note spese sono
ancora in attesa di approvazione?») l'agente ha risposto «0»: ha filtrato
`stato = 'in attesa di approvazione'`, mentre nella tabella il valore è `'in attesa'`. Nella
griglia era passata perché il modello, dopo lo zero, aveva rifatto la query raggruppando per
stato; in chat non lo ha fatto. Lo stesso indovinare si vedeva nella domanda 6 di Valdoria
(`tessera LIKE '%student%'`): giusta per fortuna.

**Cosa ho cambiato.** Una cosa sola, nel backend (`tables.schema_testo`): sotto ogni tabella, la
descrizione di `query_db` elenca i valori delle colonne di testo che ne hanno al massimo 12, tutti
brevi (`valori di stato: 'approvata', 'in attesa', 'respinta'`), e dice di usare i valori esatti,
o di guardarli con una `SELECT DISTINCT` per le colonne senza elenco. Valgono per tutti gli agenti,
anche per quelli degli utenti.

**Le altre dopo la modifica.** Cambia il comportamento di tutti e due gli agenti, quindi rilanciate
tutte e due le griglie con tre lanci: 27/27 e 27/27. La domanda 5 ora filtra `'in attesa'` al primo
colpo, la 6 di Valdoria `tessera = 'studenti'`.

### Cura 3: la data di oggi nelle regole fisse

**Cosa succedeva.** L'agente non sapeva che giorno fosse. Alla domanda 7 di Valdoria («Il
prestito 620 si può rinnovare ancora?») nessuna risposta diceva che il prestito era anche
scaduto, il 18 ottobre 2026, e una diceva «ancora attivo».

**Cosa ho cambiato.** Una cosa sola, nel backend (`agent.istruzioni_complete`): tra le regole fisse
c'è la data di oggi in Italia («Oggi è giovedì 5 novembre 2026: tienine conto per scadenze,
periodi in vigore e domande come «quest'anno»»). Vale per tutti gli agenti.

**Come si valutano le domande che dipendono dalla data** (scritto prima di rilanciare). Con la
data, la domanda 1 di Valtesa può avere come risposta solo il massimale in vigore oggi. È
giusta se dà 40,00 € e dice che vale dal 1° luglio 2026, anche senza i 35,00 € di prima; è
sbagliata se dà 35,00 € come massimale di oggi. Per la domanda 7 di Valdoria resta giusta la
risposta «No» con i due rinnovi già fatti, e anche quella che aggiunge la scadenza passata.

**Le altre dopo la modifica.** Valdoria 27/27, e ora alla domanda 7 tutti e tre i lanci dicono che
il prestito è scaduto il 18 ottobre 2026. La domanda 1 di Valtesa dà il massimale di oggi (40 €,
dal 1° luglio), a volte anche quello di prima. Valtesa però scende a 25/27, e un secondo giro
senza cambiare niente dà di nuovo 25/27 con errori su un'altra domanda:

| Giro | Domanda 3 (corsi sicurezza) | Domanda 4 (Sara Conti) | Domanda 7 (nota 1043) |
|---|---|---|---|
| primo | 2/3: una volta solo la formazione generale | 3/3 | 2/3: una volta la policy da 35 € senza la circolare |
| secondo | 3/3 | 1/3: due volte la somma «36» | 3/3 |

Sono le tre domande che erano instabili anche nella griglia del docente: gli errori sono gli
stessi (la tabella dei corsi divisa tra due chunk, le omonime sommate, la circolare non trovata) e
cambiano da un giro all'altro, mentre la data non entra in nessuna di queste risposte. La cura 3
resta: sistema quello che doveva e non rompe niente in modo ripetibile. Le cure per queste tre
sono quelle del docente: la riga sulle omonime nelle istruzioni di Valtesa (sua cura 1) e K 8
invece di 4 (sua cura 2), una alla volta.

**Rimaste, senza errori ma da sapere.**
- A volte una risposta scrive tra `[ ]` qualcosa che non è un chunk: il nome di un file
  (`[regolamento-sala-studio.md]`) o di un tool (`[query_db]`). L'interfaccia lo lascia come testo.
- Nessuna risposta alla domanda 7 dice che il prestito è anche scaduto (18 ottobre 2026): l'agente
  non conosce la data di oggi. Una data nelle regole fisse del backend lo permetterebbe.
- Un follow-up può citare un chunk restituito nel turno precedente: la citazione è giusta, ma
  l'interfaccia mostra come fonti solo i chunk di quel turno.
