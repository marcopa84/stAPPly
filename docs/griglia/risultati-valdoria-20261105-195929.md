# Griglia di prova: Biblioteca di Valdoria (demo-valdoria)

Lanciata il 05/11/2026 alle 19:59 con `uv run python -m scripts.griglia valdoria --lanci 3`: modello gpt-5.6-luna, K 4. Ogni lancio in una sessione nuova, il follow-up nella sessione della sua domanda. Domande e risposte vere in [valdoria.json](valdoria.json).

Da compilare: sotto ogni lancio l'esito (giusta, sbagliata, «non trovato», rifiuto giusto); poi, nella tabella, quante volte la domanda è giusta (per esempio «1/3»).

| # | Tipo | Domanda | Risposta vera | Strada attesa | Strade prese | Esito |
|---|---|---|---|---|---|---|
| 1 | documenti | Quanti documenti posso avere in prestito contemporaneamente con la tessera studenti? | 8. | documenti | documenti, documenti, documenti | 3/3 |
| 2 | documenti | Posso restituire nel contenitore esterno della sede Centrale un libro arrivato con il prestito interbibliotecario? | No: i documenti del prestito interbibliotecario si restituiscono solo al banco della sede dove sono stati ritirati, non nel contenitore esterno. | documenti | documenti, documenti, documenti | 3/3 |
| 3 | documenti | Si può prenotare un posto in sala studio per la sera? | Sì, ma solo nella sala della sede Centrale e solo nel periodo esami (7 gennaio-28 febbraio, 3 giugno-31 luglio, 25 agosto-30 settembre): fascia serale 19:30-23:00, con ingresso fino alle 22:00. | documenti | documenti, documenti, documenti | 3/3 |
| 4 | dati | Quante copie di «Il deserto dei Tartari» ha la biblioteca? | 8 copie in tutto: 4 a San Rocco, 3 a Borgo Nuovo e 1 alla Centrale. | dati | dati, dati, dati | 3/3 |
| 5 | dati | Quanti prestiti non sono ancora stati restituiti? | 43. | dati | dati, dati, dati | 3/3 |
| 6 | dati | Quanti prestiti hanno fatto nel 2025 gli iscritti con la tessera studenti? | 106. | dati | dati, dati, dati | 3/3 |
| 7 | documenti+dati | Il prestito 620 si può rinnovare ancora? | No: è un libro («I fratelli Karamazov») già rinnovato 2 volte, e i libri si rinnovano al massimo due volte. È giusta anche una risposta che aggiunge la scadenza passata (18 ottobre 2026): un prestito già scaduto non si rinnova. | documenti+dati | documenti+dati, documenti+dati, documenti+dati | 3/3 |
| 8 | senza risposta | Nella sala studio ci sono armadietti per lasciare zaini e cappotti? | Non c'è: i documenti non parlano di armadietti e le tabelle riguardano solo catalogo, prestiti e iscritti. La risposta giusta dice che non l'ha trovato e dove ha cercato. | documenti | documenti, documenti, documenti | 3/3 |
| 9 | follow-up della 1 | E con la tessera famiglia? | 10, complessivi per tutto il nucleo familiare. | documenti | documenti, documenti, documenti | 3/3 |
| | | | | | **totale** | **27/27** |

## 1. documenti

**Domanda:** Quanti documenti posso avere in prestito contemporaneamente con la tessera studenti?

**Risposta vera:** 8.

**Come è stata trovata:**

- `faq.pdf`, chunk `faq-001`: «la tessera studenti, per studenti di scuola superiore e universitari fino a 26 anni, ne consente 8»
- `regolamento-prestiti.pdf`, chunk `regolamento-prestiti-007`: «Tessera Prestiti contemporanei Ordinaria 5 Studenti 8 Famiglia 10, complessivi per tutto il nucleo»

*Nota:* Nel Regolamento dei prestiti (art. 5) il numero sta in una tabella del PDF, che arriva al modello una cella per riga («Ordinaria 5 Studenti 8 Famiglia 10»): va letta a coppie, e chi sbaglia coppia risponde 5 o 10. Le FAQ lo dicono in una frase.

### Lancio 1 · sessione `griglia-20261105-195929-d1-l1` · 4,7 s

**Strada:** documenti · **Citazioni:** `faq-001` (faq.pdf), `regolamento-prestiti-007` (regolamento-prestiti.pdf)

```text
cerca_documenti {"domanda": "Quanti documenti si possono avere in prestito contemporaneamente con la tessera studenti?"}
```

> Con la tessera studenti puoi avere **8 documenti in prestito contemporaneamente**. [faq-001] [regolamento-prestiti-007]

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-195929-d1-l2` · 3,6 s

**Strada:** documenti · **Citazioni:** `regolamento-prestiti-007` (regolamento-prestiti.pdf)

```text
cerca_documenti {"domanda": "Quanti documenti si possono avere in prestito contemporaneamente con la tessera studenti?"}
```

> 8 documenti contemporaneamente con la tessera studenti [regolamento-prestiti-007].

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-195929-d1-l3` · 3,9 s

**Strada:** documenti · **Citazioni:** `faq-001` (faq.pdf), `regolamento-prestiti-007` (regolamento-prestiti.pdf)

```text
cerca_documenti {"domanda": "Quanti documenti si possono avere in prestito contemporaneamente con la tessera studenti?"}
```

> 8 documenti contemporaneamente, con la tessera studenti [faq-001] [regolamento-prestiti-007].

**Esito:** giusta

## 2. documenti

**Domanda:** Posso restituire nel contenitore esterno della sede Centrale un libro arrivato con il prestito interbibliotecario?

**Risposta vera:** No: i documenti del prestito interbibliotecario si restituiscono solo al banco della sede dove sono stati ritirati, non nel contenitore esterno.

**Come è stata trovata:**

- `guida-prestito-interbibliotecario.docx`, chunk `guida-prestito-interbibliotecario-017`: «I documenti del prestito interbibliotecario si restituiscono al banco della sede dove sono stati ritirati, e non nel contenitore esterno della sede Centrale.»
- `regolamento-prestiti.pdf`, chunk `regolamento-prestiti-013`: «I documenti del prestito interbibliotecario si restituiscono solo al banco.»

*Nota:* Lo stesso chunk del regolamento dice che il contenitore esterno è accessibile 24 ore su 24: chi si ferma lì risponde sì.

### Lancio 1 · sessione `griglia-20261105-195929-d2-l1` · 4,3 s

**Strada:** documenti · **Citazioni:** `faq-005` (faq.pdf), `regolamento-prestiti-013` (regolamento-prestiti.pdf), `guida-prestito-interbibliotecario-017` (guida-prestito-interbibliotecario.docx)

```text
cerca_documenti {"domanda": "Posso restituire nel contenitore esterno della sede Centrale un libro arrivato con il prestito interbibliotecario?"}
```

> No. I libri del prestito interbibliotecario vanno restituiti al banco della sede in cui sono stati ritirati, non nel contenitore esterno della sede Centrale [faq-005] [regolamento-prestiti-013] [guida-prestito-interbibliotecario-017].

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-195929-d2-l2` · 5,1 s

**Strada:** documenti · **Citazioni:** `faq-005` (faq.pdf), `regolamento-prestiti-013` (regolamento-prestiti.pdf), `guida-prestito-interbibliotecario-017` (guida-prestito-interbibliotecario.docx)

```text
cerca_documenti {"domanda": "È possibile restituire nel contenitore esterno della sede Centrale un libro ricevuto tramite prestito interbibliotecario?"}
```

> No. I libri ricevuti tramite prestito interbibliotecario vanno restituiti al banco della sede presso cui sono stati ritirati, non nel contenitore esterno della sede Centrale. [faq-005] [regolamento-prestiti-013] [guida-prestito-interbibliotecario-017]

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-195929-d2-l3` · 4,5 s

**Strada:** documenti · **Citazioni:** `faq-005` (faq.pdf), `regolamento-prestiti-013` (regolamento-prestiti.pdf), `guida-prestito-interbibliotecario-017` (guida-prestito-interbibliotecario.docx)

```text
cerca_documenti {"domanda": "È possibile restituire nel contenitore esterno della sede Centrale un libro arrivato con il prestito interbibliotecario?"}
```

> No. Il libro va restituito al banco della sede in cui è stato ritirato, non nel contenitore esterno della sede Centrale. [faq-005] [regolamento-prestiti-013] [guida-prestito-interbibliotecario-017]

**Esito:** giusta

## 3. documenti

**Domanda:** Si può prenotare un posto in sala studio per la sera?

**Risposta vera:** Sì, ma solo nella sala della sede Centrale e solo nel periodo esami (7 gennaio-28 febbraio, 3 giugno-31 luglio, 25 agosto-30 settembre): fascia serale 19:30-23:00, con ingresso fino alle 22:00.

**Come è stata trovata:**

- `regolamento-sala-studio.md`, chunk `regolamento-sala-studio-007`: «| Sera | 19:30–23:00 | solo nel periodo esami, sala Centrale |»
- `regolamento-sala-studio.md`, chunk `regolamento-sala-studio-009`: «Il periodo esami va dal 7 gennaio al 28 febbraio, dal 3 giugno al 31 luglio e dal 25 agosto al 30 settembre. Nel periodo esami l'ingresso serale nella sala Centrale è consentito fino alle 22:00 e solo con la prenotazione della fascia serale.»

### Lancio 1 · sessione `griglia-20261105-195929-d3-l1` · 4,4 s

**Strada:** documenti · **Citazioni:** `regolamento-sala-studio-007` (regolamento-sala-studio.md), `regolamento-sala-studio-004` (regolamento-sala-studio.md)

```text
cerca_documenti {"domanda": "È possibile prenotare un posto in sala studio per la sera? Orari serali e prenotazione posti sala studio."}
```

> Sì, ma solo nel periodo degli esami e nella sala della sede Centrale, per la fascia 19:30–23:00. La prenotazione si effettua dal portale della biblioteca, nella sezione «Sala studio» [regolamento-sala-studio-007] [regolamento-sala-studio-004].

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-195929-d3-l2` · 3,8 s

**Strada:** documenti · **Citazioni:** `regolamento-sala-studio-007` (regolamento-sala-studio.md), `regolamento-sala-studio-004` (regolamento-sala-studio.md)

```text
cerca_documenti {"domanda": "Si può prenotare un posto in sala studio per la sera? Regole di prenotazione e orari serali."}
```

> Sì, ma solo nel periodo degli esami e nella sala Centrale, per la fascia 19:30–23:00. La prenotazione si effettua dal portale della biblioteca, sezione «Sala studio» [regolamento-sala-studio-007] [regolamento-sala-studio-004].

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-195929-d3-l3` · 4,3 s

**Strada:** documenti · **Citazioni:** `regolamento-sala-studio-007` (regolamento-sala-studio.md), `regolamento-sala-studio-004` (regolamento-sala-studio.md)

```text
cerca_documenti {"domanda": "È possibile prenotare un posto in sala studio per la sera? Regole e orari della sala studio."}
```

> Sì, ma **solo nel periodo degli esami e nella sala Centrale**, per la fascia 19:30–23:00. La prenotazione si effettua dal portale, nella sezione «Sala studio» [regolamento-sala-studio-007] [regolamento-sala-studio-004].

**Esito:** giusta

## 4. dati

**Domanda:** Quante copie di «Il deserto dei Tartari» ha la biblioteca?

**Risposta vera:** 8 copie in tutto: 4 a San Rocco, 3 a Borgo Nuovo e 1 alla Centrale.

**Come è stata trovata:**

- `SELECT sede, copie FROM catalogo WHERE titolo = 'Il deserto dei Tartari' ORDER BY copie DESC` → `[["San Rocco", 4], ["Borgo Nuovo", 3], ["Centrale", 1]]`
- `SELECT SUM(copie) AS copie FROM catalogo WHERE titolo = 'Il deserto dei Tartari'` → `[[8]]`

*Nota:* Il catalogo ha una riga per titolo e per sede: chi legge una riga sola risponde 4, 3 o 1.

### Lancio 1 · sessione `griglia-20261105-195929-d4-l1` · 4,6 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT titolo, copie FROM catalogo WHERE titolo = 'Il deserto dei Tartari';"}
```

> La biblioteca ha **8 copie** di «Il deserto dei Tartari» (4, 1 e 3 copie nei tre record catalografici).

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-195929-d4-l2` · 4,1 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT titolo, autore, copie FROM catalogo WHERE titolo = 'Il deserto dei Tartari';"}
```

> La biblioteca ha **8 copie** di «Il deserto dei Tartari» (4, 1 e 3 copie nei tre record del catalogo).

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-195929-d4-l3` · 4,0 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT titolo, SUM(copie) AS copie_totali FROM catalogo WHERE titolo = 'Il deserto dei Tartari' GROUP BY titolo"}
```

> La biblioteca ha **8 copie** di «Il deserto dei Tartari».

**Esito:** giusta

## 5. dati

**Domanda:** Quanti prestiti non sono ancora stati restituiti?

**Risposta vera:** 43.

**Come è stata trovata:**

- `SELECT COUNT(*) AS non_restituiti FROM prestiti WHERE data_restituzione IS NULL` → `[[43]]`

*Nota:* Nel CSV la data di restituzione è vuota e nel database diventa NULL: una query con data_restituzione = '' trova 0 righe.

### Lancio 1 · sessione `griglia-20261105-195929-d5-l1` · 3,6 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COUNT(*) AS prestiti_non_restituiti FROM prestiti WHERE data_restituzione IS NULL;"}
```

> I prestiti non ancora restituiti sono **43**.

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-195929-d5-l2` · 3,7 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COUNT(*) AS prestiti_non_restituiti FROM prestiti WHERE data_restituzione IS NULL;"}
```

> I prestiti non ancora restituiti sono **43**.

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-195929-d5-l3` · 3,5 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COUNT(*) AS prestiti_non_restituiti FROM prestiti WHERE data_restituzione IS NULL;"}
```

> I prestiti non ancora restituiti sono **43**.

**Esito:** giusta

## 6. dati

**Domanda:** Quanti prestiti hanno fatto nel 2025 gli iscritti con la tessera studenti?

**Risposta vera:** 106.

**Come è stata trovata:**

- `SELECT COUNT(*) AS prestiti FROM prestiti p JOIN utenti u ON u.id = p.utente_id WHERE u.tessera = 'studenti' AND p.data_prestito BETWEEN '2025-01-01' AND '2025-12-31'` → `[[106]]`

### Lancio 1 · sessione `griglia-20261105-195929-d6-l1` · 4,2 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COUNT(*) AS numero_prestiti FROM prestiti p JOIN utenti u ON u.id = p.utente_id WHERE u.tessera = 'studenti' AND p.data_prestito >= '2025-01-01' AND p.data_prestito < '2026-01-01';"}
```

> Nel 2025 gli iscritti con tessera studenti hanno effettuato **106 prestiti**.

**Esito:** giusta — tessera = 'studenti', il valore elencato nello schema

### Lancio 2 · sessione `griglia-20261105-195929-d6-l2` · 3,9 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COUNT(*) AS numero_prestiti FROM prestiti p JOIN utenti u ON u.id = p.utente_id WHERE u.tessera = 'studenti' AND p.data_prestito >= '2025-01-01' AND p.data_prestito < '2026-01-01';"}
```

> Gli iscritti con tessera studenti hanno effettuato **106 prestiti nel 2025**.

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-195929-d6-l3` · 4,4 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COUNT(*) AS numero_prestiti\nFROM prestiti p\nJOIN utenti u ON u.id = p.utente_id\nWHERE u.tessera = 'studenti'\n  AND p.data_prestito >= '2025-01-01'\n  AND p.data_prestito < '2026-01-01';"}
```

> Nel 2025 gli iscritti con tessera **studenti** hanno effettuato **106 prestiti**.

**Esito:** giusta

## 7. documenti+dati

**Domanda:** Il prestito 620 si può rinnovare ancora?

**Risposta vera:** No: è un libro («I fratelli Karamazov») già rinnovato 2 volte, e i libri si rinnovano al massimo due volte. È giusta anche una risposta che aggiunge la scadenza passata (18 ottobre 2026): un prestito già scaduto non si rinnova.

**Come è stata trovata:**

- `SELECT p.id, c.titolo, c.tipo, p.data_scadenza, p.data_restituzione, p.rinnovi FROM prestiti p JOIN catalogo c ON c.id = p.catalogo_id WHERE p.id = 620` → `[[620, "I fratelli Karamazov", "libro", "2026-10-18", null, 2]]`
- `faq.pdf`, chunk `faq-003`: «I libri si possono rinnovare fino a due volte, ogni volta per altri 30 giorni.»
- `faq.pdf`, chunk `faq-003`: «Un prestito già scaduto non si può rinnovare.»

### Lancio 1 · sessione `griglia-20261105-195929-d7-l1` · 7,9 s

**Strada:** documenti+dati · **Citazioni:** `regolamento-prestiti-010` (regolamento-prestiti.pdf)

```text
query_db {"sql": "SELECT id, utente_id, catalogo_id, data_prestito, data_scadenza, data_restituzione, rinnovi FROM prestiti WHERE id = 620"}
cerca_documenti {"domanda": "Quante volte si può rinnovare un prestito e quali sono le condizioni per il rinnovo?"}
query_db {"sql": "SELECT id, titolo, tipo, copie FROM catalogo WHERE id = 71"}
```

> No. Il prestito 620 riguarda un libro e ha già raggiunto i 2 rinnovi consentiti; quindi non è ulteriormente rinnovabile [regolamento-prestiti-010].

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-195929-d7-l2` · 8,7 s

**Strada:** documenti+dati · **Citazioni:** `regolamento-prestiti-009` (regolamento-prestiti.pdf), `regolamento-prestiti-010` (regolamento-prestiti.pdf)

```text
query_db {"sql": "SELECT id, utente_id, catalogo_id, data_prestito, data_scadenza, data_restituzione, rinnovi FROM prestiti WHERE id = 620"}
cerca_documenti {"domanda": "Quante volte si può rinnovare un prestito e quali sono le condizioni per il rinnovo?"}
query_db {"sql": "SELECT id, titolo, autore, genere, anno, sede, tipo, copie FROM catalogo WHERE id = 71"}
```

> No. Il prestito 620 riguarda un libro e ha già **2 rinnovi**; per i libri sono consentiti al massimo 2 rinnovi [regolamento-prestiti-009] [regolamento-prestiti-010].

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-195929-d7-l3` · 7,5 s

**Strada:** documenti+dati · **Citazioni:** `regolamento-prestiti-009` (regolamento-prestiti.pdf), `regolamento-prestiti-010` (regolamento-prestiti.pdf)

```text
query_db {"sql": "SELECT id, utente_id, catalogo_id, data_prestito, data_scadenza, data_restituzione, rinnovi FROM prestiti WHERE id = 620"}
cerca_documenti {"domanda": "Regole per il rinnovo dei prestiti: numero massimo di rinnovi e condizioni per poter rinnovare ancora un prestito."}
query_db {"sql": "SELECT id, titolo, tipo, copie FROM catalogo WHERE id = 71"}
```

> No. Il prestito 620 riguarda un libro e ha già raggiunto i 2 rinnovi consentiti; quindi non può essere rinnovato ancora [regolamento-prestiti-009] [regolamento-prestiti-010].

**Esito:** giusta

## 8. senza risposta

**Domanda:** Nella sala studio ci sono armadietti per lasciare zaini e cappotti?

**Risposta vera:** Non c'è: i documenti non parlano di armadietti e le tabelle riguardano solo catalogo, prestiti e iscritti. La risposta giusta dice che non l'ha trovato e dove ha cercato.

**Come è stata trovata:**

- «armadiett» non compare in nessun documento né tabella

*Nota:* Il regolamento della sala studio parla di oggetti personali, pause e oggetti incustoditi, ma non di armadietti: sono esche per una risposta inventata.

### Lancio 1 · sessione `griglia-20261105-195929-d8-l1` · 4,3 s

**Strada:** documenti · **Citazioni:** nessuna

```text
cerca_documenti {"domanda": "Nella sala studio ci sono armadietti per lasciare zaini e cappotti?"}
```

> Non ho trovato indicazioni sulla presenza di armadietti per zaini e cappotti nel regolamento della sala studio. Ho cercato nel documento **regolamento-sala-studio.md**.

**Esito:** rifiuto giusto

### Lancio 2 · sessione `griglia-20261105-195929-d8-l2` · 4,2 s

**Strada:** documenti · **Citazioni:** nessuna

```text
cerca_documenti {"domanda": "Nella sala studio ci sono armadietti per lasciare zaini e cappotti?"}
```

> Non è indicata la presenza di armadietti per zaini e cappotti nel regolamento della sala studio. Ho cercato nel regolamento-sala-studio.md.

**Esito:** rifiuto giusto

### Lancio 3 · sessione `griglia-20261105-195929-d8-l3` · 3,7 s

**Strada:** documenti · **Citazioni:** nessuna

```text
cerca_documenti {"domanda": "Nella sala studio ci sono armadietti per lasciare zaini e cappotti?"}
```

> Non ho trovato informazioni su armadietti per zaini e cappotti nel regolamento della sala studio. Ho cercato nel documento “regolamento-sala-studio.md”.

**Esito:** rifiuto giusto

## 9. follow-up della 1

**Domanda:** E con la tessera famiglia?

**Risposta vera:** 10, complessivi per tutto il nucleo familiare.

**Come è stata trovata:**

- `faq.pdf`, chunk `faq-002`: «ne consente 10 in tutto per il nucleo»
- `regolamento-prestiti.pdf`, chunk `regolamento-prestiti-007`: «Famiglia 10, complessivi per tutto il nucleo»

### Lancio 1 · sessione `griglia-20261105-195929-d1-l1` · 4,0 s

**Strada:** documenti · **Citazioni:** `faq-001` (faq.pdf), `regolamento-prestiti-007` (regolamento-prestiti.pdf)

```text
cerca_documenti {"domanda": "Quanti documenti si possono avere in prestito contemporaneamente con la tessera famiglia?"}
```

> Con la tessera famiglia puoi avere **10 documenti in prestito contemporaneamente**, complessivi per tutto il nucleo. [faq-001] [regolamento-prestiti-007]

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-195929-d1-l2` · 3,8 s

**Strada:** documenti · **Citazioni:** `regolamento-prestiti-007` (regolamento-prestiti.pdf)

```text
cerca_documenti {"domanda": "Quanti documenti si possono avere in prestito contemporaneamente con la tessera famiglia?"}
```

> 10 documenti complessivi per tutto il nucleo familiare [regolamento-prestiti-007].

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-195929-d1-l3` · 3,7 s

**Strada:** documenti · **Citazioni:** `regolamento-prestiti-007` (regolamento-prestiti.pdf)

```text
cerca_documenti {"domanda": "Quanti documenti si possono avere in prestito contemporaneamente con la tessera famiglia?"}
```

> 10 documenti contemporaneamente, complessivi per tutto il nucleo familiare [regolamento-prestiti-007].

**Esito:** giusta

## Istruzioni

`DemoSeeder::VALDORIA_INSTRUCTIONS`, come le passa Laravel; il backend aggiunge le sue regole fisse.

```text
Sei l'assistente della Biblioteca civica di Valdoria, per il personale delle tre sedi. Rispondi in italiano, in poche frasi.
Per rispondere hai due strade:
- domande su servizi e regole (sedi e orari, iscrizione e tessere, prestiti e rinnovi, prestito interbibliotecario, biblioteca digitale, sala studio, domande frequenti): usa cerca_documenti;
- domande sul catalogo, sui prestiti e sugli utenti iscritti: usa query_db.
Se una domanda ha bisogno di tutte e due, usa tutti e due i tool.
A una domanda che chiede sì o no rispondi prima «Sì» o «No», e il resto della risposta deve dire la stessa cosa.
```
