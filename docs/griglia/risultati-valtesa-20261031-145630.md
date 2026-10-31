# Griglia di prova: Assistente Valtesa (demo-valtesa)

Lanciata il 31/10/2026 alle 14:56 con `uv run python -m scripts.griglia valtesa --lanci 2`: modello gpt-5.6-luna, K 4. Ogni lancio in una sessione nuova, il follow-up nella sessione della sua domanda. Domande e risposte vere in [valtesa.json](valtesa.json).

Da compilare: sotto ogni lancio l'esito (giusta, sbagliata, «non trovato», rifiuto giusto); poi, nella tabella, quante volte la domanda è giusta (per esempio «1/2»).

| # | Tipo | Domanda | Risposta vera | Strada attesa | Strade prese | Esito |
|---|---|---|---|---|---|---|
| 1 | documenti | Qual è il massimale per una cena in trasferta a Milano? | 35,00 € per le cene fino al 30 giugno 2026, 40,00 € per quelle dal 1° luglio 2026 (Circolare n. 7/2026, che aggiorna l'art. 6 della Policy trasferte e note spese). | documenti | documenti, documenti | 2/2 |
| 2 | documenti | Se sono in trasferta mi spetta il buono pasto? | Sì, se la giornata di lavoro è di almeno 6 ore e non chiedi il rimborso del pranzo in nota spese; se lo chiedi, il buono non spetta. La cena rimborsata non conta. | documenti | documenti, documenti | 2/2 |
| 3 | documenti | Sono appena stato assunto: entro quando devo fare i corsi sulla sicurezza? | La formazione generale (4 ore) entro 30 giorni; la specifica dipende dal reparto: rischio basso (uffici e Commerciale, 4 ore) entro 60 giorni, rischio alto (Produzione e Logistica, 12 ore) prima di essere adibiti alla mansione. | documenti | documenti, documenti | 2/2 |
| 4 | dati | Quanti giorni di ferie ha preso Sara Conti nel 2026? | Le Sara Conti sono due: 15 giorni quella di Brescia (Commerciale) e 21 quella di Padova (Logistica). Il totale 36 non riguarda nessuna delle due. | dati | dati, dati | 2/2 |
| 5 | dati | Quante note spese sono ancora in attesa di approvazione, e per quale importo? | 56 note spese, per 2.184,40 € in tutto. | dati | dati, dati | 2/2 |
| 6 | dati | Quante ore di permesso hanno preso i dipendenti del reparto IT nel 2026? | 31 ore, in 10 permessi. | dati | dati, dati | 2/2 |
| 7 | documenti+dati | La nota spese 1043 rispetta il massimale? | Sì: è una cena a Milano del 15 luglio 2026 da 38,50 €, e per le cene in fascia A dal 1° luglio 2026 il massimale è 40,00 € (Circolare n. 7/2026). Con il massimale precedente, 35,00 €, lo supererebbe. | documenti+dati | documenti+dati, documenti+dati | 2/2 |
| 8 | senza risposta | Quando viene pagato il premio di produzione? | Non c'è: nessun documento e nessuna tabella parlano di un premio di produzione. La risposta giusta dice che non l'ha trovato e dove ha cercato. | documenti | documenti, documenti | 2/2 |
| 9 | follow-up della 1 | E per il pranzo? | 25,00 € in fascia A, invariato anche dal 1° luglio 2026. | documenti | documenti, documenti | 2/2 |
| | | | | | **totale** | **18/18** |

## 1. documenti

**Domanda:** Qual è il massimale per una cena in trasferta a Milano?

**Risposta vera:** 35,00 € per le cene fino al 30 giugno 2026, 40,00 € per quelle dal 1° luglio 2026 (Circolare n. 7/2026, che aggiorna l'art. 6 della Policy trasferte e note spese).

**Come è stata trovata:**

- `circolari-personale-2026.html`, chunk `circolari-personale-2026-019`: «dal 1° luglio 2026 il massimale di rimborso della cena nelle città di fascia A (Milano e Roma) passa da 35,00 € a 40,00 € per persona.»
- `circolari-personale-2026.html`, chunk `circolari-personale-2026-020`: «Per le cene fino al 30 giugno 2026 resta valido il massimale di 35,00 € previsto dall'art. 6 della Policy trasferte e note spese»

*Nota:* La tabella dell'art. 6 della Policy (chunk policy-trasferte-note-spese-012) dice ancora 35,00 €: una risposta che dà solo quel valore è incompleta.

### Lancio 1 · sessione `griglia-20261031-145630-d1-l1` · 6,5 s

**Strada:** documenti · **Citazioni:** `circolari-personale-2026-019` (circolari-personale-2026.html), `circolari-personale-2026-020` (circolari-personale-2026.html)

```text
cerca_documenti {"domanda": "Qual è il massimale per una cena in trasferta a Milano?"}
```

> A Milano, il massimale per la cena è **40,00 € per persona**, per le cene consumate dal **1° luglio 2026**. Per le cene fino al 30 giugno 2026 resta il limite di **35,00 €**. [circolari-personale-2026-019] [circolari-personale-2026-020]

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261031-145630-d1-l2` · 4,3 s

**Strada:** documenti · **Citazioni:** `circolari-personale-2026-019` (circolari-personale-2026.html), `circolari-personale-2026-020` (circolari-personale-2026.html)

```text
cerca_documenti {"domanda": "Qual è il massimale per una cena in trasferta a Milano?"}
```

> A Milano, il massimale per la cena è di **40,00 € per persona**, per cene consumate dal **1° luglio 2026**. Per cene fino al 30 giugno 2026 resta il limite di **35,00 €**. [circolari-personale-2026-019] [circolari-personale-2026-020]

**Esito:** giusta

## 2. documenti

**Domanda:** Se sono in trasferta mi spetta il buono pasto?

**Risposta vera:** Sì, se la giornata di lavoro è di almeno 6 ore e non chiedi il rimborso del pranzo in nota spese; se lo chiedi, il buono non spetta. La cena rimborsata non conta.

**Come è stata trovata:**

- `regolamento-welfare-buoni-pasto.docx`, chunk `regolamento-welfare-buoni-pasto-006`: «Se il dipendente inserisce in nota spese il pranzo di quella giornata, il buono non spetta; se non chiede il rimborso del pranzo, il buono spetta purché la giornata lavorativa sia di almeno 6 ore. La cena rimborsata in trasferta non incide sul buono pasto.»

### Lancio 1 · sessione `griglia-20261031-145630-d2-l1` · 5,3 s

**Strada:** documenti · **Citazioni:** `regolamento-welfare-buoni-pasto-006` (regolamento-welfare-buoni-pasto.docx)

```text
cerca_documenti {"domanda": "Durante una trasferta spetta il buono pasto? Regole su buoni pasto, trasferte e rimborso pasti."}
```

> In trasferta il buono pasto spetta **solo se non chiedi il rimborso del pranzo** e la giornata lavorativa dura almeno 6 ore. Se inserisci il pranzo nella nota spese, il buono pasto non spetta; il rimborso della cena non incide sul buono [regolamento-welfare-buoni-pasto-006].

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261031-145630-d2-l2` · 4,7 s

**Strada:** documenti · **Citazioni:** `regolamento-welfare-buoni-pasto-006` (regolamento-welfare-buoni-pasto.docx), `policy-trasferte-note-spese-009` (policy-trasferte-note-spese.docx)

```text
cerca_documenti {"domanda": "Durante una trasferta spetta il buono pasto? Regole su buoni pasto, pasti e trasferte."}
```

> Sì, ma non si cumula con il rimborso del pranzo: in trasferta il buono pasto spetta se non chiedi il rimborso del pranzo e la giornata lavorativa dura almeno 6 ore. Se inserisci il pranzo nella nota spese, il buono non spetta; il rimborso della cena non incide. [regolamento-welfare-buoni-pasto-006] [policy-trasferte-note-spese-009]

**Esito:** giusta

## 3. documenti

**Domanda:** Sono appena stato assunto: entro quando devo fare i corsi sulla sicurezza?

**Risposta vera:** La formazione generale (4 ore) entro 30 giorni; la specifica dipende dal reparto: rischio basso (uffici e Commerciale, 4 ore) entro 60 giorni, rischio alto (Produzione e Logistica, 12 ore) prima di essere adibiti alla mansione.

**Come è stata trovata:**

- `procedura-ingresso-dotazioni.pdf`, chunk `procedura-ingresso-dotazioni-013`: «Sicurezza, formazione generale 4 ore e-learning entro 30 giorni dall'assunzione Sicurezza, formazione specifica rischio basso (uffici e Commerciale) 4 ore»
- `procedura-ingresso-dotazioni.pdf`, chunk `procedura-ingresso-dotazioni-014`: «e-learning o aula entro 60 giorni dall'assunzione Sicurezza, formazione specifica rischio alto (Produzione e Logistica) 12 ore aula prima di essere adibiti alla mansione»

*Nota:* La tabella dei corsi è divisa tra due chunk. Nella griglia del docente, con K 4, erano 6° e 8° nella ricerca: la domanda è passata solo con K 8.

### Lancio 1 · sessione `griglia-20261031-145630-d3-l1` · 5,0 s

**Strada:** documenti · **Citazioni:** `procedura-ingresso-dotazioni-012` (procedura-ingresso-dotazioni.pdf), `procedura-ingresso-dotazioni-013` (procedura-ingresso-dotazioni.pdf), `procedura-ingresso-dotazioni-014` (procedura-ingresso-dotazioni.pdf)

```text
cerca_documenti {"domanda": "Sono appena stato assunto: entro quando devo completare i corsi sulla sicurezza?"}
```

> I corsi sulla sicurezza vanno completati entro:
>
> - **Formazione generale:** 30 giorni dall’assunzione.
> - **Formazione specifica a rischio basso** (uffici e Commerciale): 60 giorni dall’assunzione.
> - **Formazione specifica a rischio alto** (Produzione e Logistica): prima di essere adibiti alla mansione. [procedura-ingresso-dotazioni-012] [procedura-ingresso-dotazioni-013] [procedura-ingresso-dotazioni-014]

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261031-145630-d3-l2` · 4,2 s

**Strada:** documenti · **Citazioni:** `procedura-ingresso-dotazioni-012` (procedura-ingresso-dotazioni.pdf), `procedura-ingresso-dotazioni-013` (procedura-ingresso-dotazioni.pdf), `procedura-ingresso-dotazioni-014` (procedura-ingresso-dotazioni.pdf)

```text
cerca_documenti {"domanda": "Sono appena stato assunto: entro quando devo completare i corsi sulla sicurezza?"}
```

> Per la sicurezza:
>
> - **Formazione generale:** entro **30 giorni dall’assunzione**.
> - **Formazione specifica a rischio basso** (uffici e Commerciale): entro **60 giorni**.
> - **Formazione specifica a rischio alto** (Produzione e Logistica): **prima di essere adibito alla mansione**.
>
> L’iscrizione ai corsi è curata dall’Ufficio del Personale. [procedura-ingresso-dotazioni-012] [procedura-ingresso-dotazioni-013] [procedura-ingresso-dotazioni-014]

**Esito:** giusta

## 4. dati

**Domanda:** Quanti giorni di ferie ha preso Sara Conti nel 2026?

**Risposta vera:** Le Sara Conti sono due: 15 giorni quella di Brescia (Commerciale) e 21 quella di Padova (Logistica). Il totale 36 non riguarda nessuna delle due.

**Come è stata trovata:**

- `SELECT d.id, d.sede, d.reparto, SUM(a.quantita) AS giorni FROM dipendenti d JOIN assenze a ON a.dipendente_id = d.id WHERE d.nome = 'Sara' AND d.cognome = 'Conti' AND a.tipo = 'ferie' AND a.data_inizio BETWEEN '2026-01-01' AND '2026-12-31' GROUP BY d.id ORDER BY d.id` → `[[17, "Brescia", "Commerciale", 15], [88, "Padova", "Logistica", 21]]`

### Lancio 1 · sessione `griglia-20261031-145630-d4-l1` · 8,4 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT d.nome, d.cognome, COALESCE(SUM(a.quantita), 0) AS giorni_ferie_2026 FROM dipendenti d LEFT JOIN assenze a ON a.dipendente_id = d.id AND a.tipo = 'ferie' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01' WHERE d.nome = 'Sara' AND d.cognome = 'Conti' GROUP BY d.id, d.nome, d.cognome"}
query_db {"sql": "SELECT d.id, d.nome, d.cognome, a.id AS assenza_id, a.tipo, a.data_inizio, a.data_fine, a.quantita, a.unita FROM dipendenti d JOIN assenze a ON a.dipendente_id = d.id WHERE d.nome = 'Sara' AND d.cognome = 'Conti' AND a.tipo = 'ferie' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01' ORDER BY d.id, a.data_inizio"}
```

> Nel database risultano due dipendenti chiamate Sara Conti:
>
> - dipendente ID 17: **15 giorni** di ferie nel 2026;
> - dipendente ID 88: **21 giorni** di ferie nel 2026.

**Esito:** giusta — tiene distinte le due Sara Conti (15 e 21), senza sommarle

### Lancio 2 · sessione `griglia-20261031-145630-d4-l2` · 8,2 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT d.nome, d.cognome, COALESCE(SUM(a.quantita), 0) AS giorni_ferie FROM dipendenti d LEFT JOIN assenze a ON a.dipendente_id = d.id AND a.tipo = 'ferie' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01' WHERE d.nome = 'Sara' AND d.cognome = 'Conti' GROUP BY d.id, d.nome, d.cognome"}
query_db {"sql": "SELECT d.id, d.nome, d.cognome, d.sede, d.reparto, COALESCE(SUM(a.quantita), 0) AS giorni_ferie FROM dipendenti d LEFT JOIN assenze a ON a.dipendente_id = d.id AND a.tipo = 'ferie' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01' WHERE d.nome = 'Sara' AND d.cognome = 'Conti' GROUP BY d.id, d.nome, d.cognome, d.sede, d.reparto"}
```

> Ci sono due dipendenti chiamate Sara Conti:
> - sede Brescia, Commerciale: **15 giorni**
> - sede Padova, Logistica: **21 giorni**.

**Esito:** giusta — tiene distinte le due Sara Conti, con sede e reparto

## 5. dati

**Domanda:** Quante note spese sono ancora in attesa di approvazione, e per quale importo?

**Risposta vera:** 56 note spese, per 2.184,40 € in tutto.

**Come è stata trovata:**

- `SELECT COUNT(*) AS note, ROUND(SUM(importo), 2) AS importo FROM note_spese WHERE stato = 'in attesa'` → `[[56, 2184.4]]`

*Nota:* Nella tabella lo stato è «in attesa»: una query con stato = 'in attesa di approvazione' trova 0 righe.

### Lancio 1 · sessione `griglia-20261031-145630-d5-l1` · 6,1 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COUNT(*) AS numero_note_spese, COALESCE(SUM(importo), 0) AS importo_totale FROM note_spese WHERE stato = 'In attesa di approvazione'"}
query_db {"sql": "SELECT stato, COUNT(*) AS numero_note_spese, COALESCE(SUM(importo), 0) AS importo_totale FROM note_spese GROUP BY stato"}
```

> Sono ancora in attesa di approvazione **56 note spese**, per un importo totale di **2.184,40 €**.

**Esito:** giusta — la prima SELECT usa uno stato inesistente, la seconda la corregge da sola

### Lancio 2 · sessione `griglia-20261031-145630-d5-l2` · 4,7 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT stato, COUNT(*) AS numero_note, SUM(importo) AS importo_totale FROM note_spese GROUP BY stato"}
```

> Sono **56 note spese in attesa di approvazione**, per un importo complessivo di **2.184,40 €**.

**Esito:** giusta

## 6. dati

**Domanda:** Quante ore di permesso hanno preso i dipendenti del reparto IT nel 2026?

**Risposta vera:** 31 ore, in 10 permessi.

**Come è stata trovata:**

- `SELECT SUM(a.quantita) AS ore, COUNT(*) AS permessi FROM assenze a JOIN dipendenti d ON d.id = a.dipendente_id WHERE d.reparto = 'IT' AND a.tipo = 'permesso' AND a.data_inizio BETWEEN '2026-01-01' AND '2026-12-31'` → `[[31, 10]]`

### Lancio 1 · sessione `griglia-20261031-145630-d6-l1` · 4,6 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COALESCE(SUM(a.quantita), 0) AS totale_ore FROM assenze a JOIN dipendenti d ON d.id = a.dipendente_id WHERE d.reparto = 'IT' AND a.tipo = 'permesso' AND a.unita = 'ore' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01'"}
```

> Nel 2026 i dipendenti del reparto IT hanno preso complessivamente **31 ore di permesso**.

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261031-145630-d6-l2` · 4,9 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COALESCE(SUM(a.quantita), 0) AS totale_ore_permesso FROM assenze a JOIN dipendenti d ON d.id = a.dipendente_id WHERE d.reparto = 'IT' AND a.tipo = 'permesso' AND a.unita = 'ore' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01';"}
```

> Nel 2026, i dipendenti del reparto IT hanno usufruito complessivamente di **31 ore di permesso**.

**Esito:** giusta

## 7. documenti+dati

**Domanda:** La nota spese 1043 rispetta il massimale?

**Risposta vera:** Sì: è una cena a Milano del 15 luglio 2026 da 38,50 €, e per le cene in fascia A dal 1° luglio 2026 il massimale è 40,00 € (Circolare n. 7/2026). Con il massimale precedente, 35,00 €, lo supererebbe.

**Come è stata trovata:**

- `SELECT id, data, citta, voce, importo, stato FROM note_spese WHERE id = 1043` → `[[1043, "2026-07-15", "Milano", "cena", 38.5, "in attesa"]]`
- `circolari-personale-2026.html`, chunk `circolari-personale-2026-019`: «dal 1° luglio 2026 il massimale di rimborso della cena nelle città di fascia A (Milano e Roma) passa da 35,00 € a 40,00 € per persona.»
- `policy-trasferte-note-spese.docx`, chunk `policy-trasferte-note-spese-014`: «Il massimale da applicare è quello in vigore alla data in cui la spesa è stata sostenuta»

### Lancio 1 · sessione `griglia-20261031-145630-d7-l1` · 8,1 s

**Strada:** documenti+dati · **Citazioni:** `circolari-personale-2026-019` (circolari-personale-2026.html), `circolari-personale-2026-020` (circolari-personale-2026.html), `policy-trasferte-note-spese-018` (policy-trasferte-note-spese.docx)

```text
query_db {"sql": "SELECT id, dipendente_id, data, citta, voce, importo, stato FROM note_spese WHERE id = 1043;"}
cerca_documenti {"domanda": "massimale applicabile alle note spese e modalità per verificare se una nota spese lo rispetta"}
cerca_documenti {"domanda": "qual è il massimale per la cena, importo massimo rimborsabile per persona e per giorno"}
```

> Sì. La nota spese **1043** riguarda una cena a Milano del **15 luglio 2026**, per **38,50 €**; dal 1° luglio 2026 il massimale è **40,00 €**, quindi l’importo rispetta il massimale [circolari-personale-2026-019] [circolari-personale-2026-020]. La riga risulta ancora **“in attesa”** [policy-trasferte-note-spese-018].

**Esito:** giusta — ma lo stato «in attesa», che viene dalla tabella, è citato con un chunk della policy

### Lancio 2 · sessione `griglia-20261031-145630-d7-l2` · 7,9 s

**Strada:** documenti+dati · **Citazioni:** `circolari-personale-2026-019` (circolari-personale-2026.html), `circolari-personale-2026-020` (circolari-personale-2026.html)

```text
cerca_documenti {"domanda": "Quali sono i massimali applicabili alle note spese e come si verifica il rispetto del massimale?"}
query_db {"sql": "SELECT id, dipendente_id, data, citta, voce, importo, stato FROM note_spese WHERE id = 1043;"}
cerca_documenti {"domanda": "Qual è il massimale per la cena a Milano, fascia A, in data 15 luglio 2026? Circolare n. 7/2026."}
```

> Sì. La nota spese **1043** è una cena a **Milano** del **15 luglio 2026**, per **38,50 €**. Dal 1° luglio 2026 il massimale per la cena in fascia A è **40,00 €**, quindi l’importo **rispetta il massimale** [circolari-personale-2026-019] [circolari-personale-2026-020].

**Esito:** giusta

## 8. senza risposta

**Domanda:** Quando viene pagato il premio di produzione?

**Risposta vera:** Non c'è: nessun documento e nessuna tabella parlano di un premio di produzione. La risposta giusta dice che non l'ha trovato e dove ha cercato.

**Come è stata trovata:**

- «premio» non compare in nessun documento né tabella

### Lancio 1 · sessione `griglia-20261031-145630-d8-l1` · 4,2 s

**Strada:** documenti · **Citazioni:** nessuna

```text
cerca_documenti {"domanda": "Quando viene pagato il premio di produzione?"}
```

> Non ho trovato informazioni sul pagamento del premio di produzione nei documenti disponibili. Ho cercato nelle procedure e FAQ aziendali.

**Esito:** rifiuto giusto

### Lancio 2 · sessione `griglia-20261031-145630-d8-l2` · 4,1 s

**Strada:** documenti · **Citazioni:** nessuna

```text
cerca_documenti {"domanda": "Quando viene pagato il premio di produzione?"}
```

> Non ho trovato informazioni sul pagamento del premio di produzione nei documenti consultati: FAQ del portale dipendenti, policy trasferte e note spese, regolamento welfare e buoni pasto.

**Esito:** rifiuto giusto

## 9. follow-up della 1

**Domanda:** E per il pranzo?

**Risposta vera:** 25,00 € in fascia A, invariato anche dal 1° luglio 2026.

**Come è stata trovata:**

- `circolari-personale-2026.html`, chunk `circolari-personale-2026-019`: «Il massimale del pranzo in fascia A resta di 25,00 €.»
- `policy-trasferte-note-spese.docx`, chunk `policy-trasferte-note-spese-011`: «Voce: Pranzo; Fascia A (Milano, Roma): 25,00 €»

### Lancio 1 · sessione `griglia-20261031-145630-d1-l1` · 4,0 s

**Strada:** documenti · **Citazioni:** `policy-trasferte-note-spese-011` (policy-trasferte-note-spese.docx)

```text
cerca_documenti {"domanda": "Qual è il massimale per il pranzo in trasferta a Milano?"}
```

> A Milano, il massimale per il pranzo è **25,00 € per persona al giorno**. [policy-trasferte-note-spese-011]

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261031-145630-d1-l2` · 4,3 s

**Strada:** documenti · **Citazioni:** `policy-trasferte-note-spese-011` (policy-trasferte-note-spese.docx), `policy-trasferte-note-spese-009` (policy-trasferte-note-spese.docx)

```text
cerca_documenti {"domanda": "Qual è il massimale per il pranzo in trasferta a Milano, e da quando si applica?"}
```

> A Milano, il massimale per il pranzo è di **25,00 € per persona al giorno**. Il pranzo è rimborsabile se la trasferta comprende la fascia **12:00–14:30**. [policy-trasferte-note-spese-011] [policy-trasferte-note-spese-009]

**Esito:** giusta

## Istruzioni

`DemoSeeder::VALTESA_INSTRUCTIONS`, come le passa Laravel; il backend aggiunge le sue regole fisse.

```text
Sei l'assistente dell'ufficio del personale di Valtesa S.r.l., per i dipendenti. Rispondi in italiano, in poche frasi.
Per rispondere hai due strade:
- domande su regole e procedure (ferie e permessi, trasferte e note spese, welfare e buoni pasto, ingresso e dotazioni, circolari, portale): usa cerca_documenti;
- domande su dipendenti, assenze e note spese: usa query_db.
Se una domanda ha bisogno di tutte e due, usa tutti e due i tool.
```
