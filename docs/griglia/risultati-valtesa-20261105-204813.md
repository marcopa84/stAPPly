# Griglia di prova: Assistente Valtesa (demo-valtesa)

Lanciata il 05/11/2026 alle 20:48 con `uv run python -m scripts.griglia valtesa --lanci 3`: modello gpt-5.6-luna, K 4. Ogni lancio in una sessione nuova, il follow-up nella sessione della sua domanda. Domande e risposte vere in [valtesa.json](valtesa.json).

Da compilare: sotto ogni lancio l'esito (giusta, sbagliata, «non trovato», rifiuto giusto); poi, nella tabella, quante volte la domanda è giusta (per esempio «1/3»).

| # | Tipo | Domanda | Risposta vera | Strada attesa | Strade prese | Esito |
|---|---|---|---|---|---|---|
| 1 | documenti | Qual è il massimale per una cena in trasferta a Milano? | 35,00 € per le cene fino al 30 giugno 2026, 40,00 € per quelle dal 1° luglio 2026 (Circolare n. 7/2026, che aggiorna l'art. 6 della Policy trasferte e note spese). | documenti | documenti, documenti, documenti | 3/3 |
| 2 | documenti | Se sono in trasferta mi spetta il buono pasto? | Sì, se la giornata di lavoro è di almeno 6 ore e non chiedi il rimborso del pranzo in nota spese; se lo chiedi, il buono non spetta. La cena rimborsata non conta. | documenti | documenti, documenti, documenti | 3/3 |
| 3 | documenti | Sono appena stato assunto: entro quando devo fare i corsi sulla sicurezza? | La formazione generale (4 ore) entro 30 giorni; la specifica dipende dal reparto: rischio basso (uffici e Commerciale, 4 ore) entro 60 giorni, rischio alto (Produzione e Logistica, 12 ore) prima di essere adibiti alla mansione. | documenti | documenti, documenti, documenti | 3/3 |
| 4 | dati | Quanti giorni di ferie ha preso Sara Conti nel 2026? | Le Sara Conti sono due: 15 giorni quella di Brescia (Commerciale) e 21 quella di Padova (Logistica). Il totale 36 non riguarda nessuna delle due. | dati | dati, dati, dati | 1/3 |
| 5 | dati | Quante note spese sono ancora in attesa di approvazione, e per quale importo? | 56 note spese, per 2.184,40 € in tutto. | dati | dati, dati, dati | 3/3 |
| 6 | dati | Quante ore di permesso hanno preso i dipendenti del reparto IT nel 2026? | 31 ore, in 10 permessi. | dati | dati, dati, dati | 3/3 |
| 7 | documenti+dati | La nota spese 1043 rispetta il massimale? | Sì: è una cena a Milano del 15 luglio 2026 da 38,50 €, e per le cene in fascia A dal 1° luglio 2026 il massimale è 40,00 € (Circolare n. 7/2026). Con il massimale precedente, 35,00 €, lo supererebbe. | documenti+dati | documenti+dati, documenti+dati, documenti+dati | 3/3 |
| 8 | senza risposta | Quando viene pagato il premio di produzione? | Non c'è: nessun documento e nessuna tabella parlano di un premio di produzione. La risposta giusta dice che non l'ha trovato e dove ha cercato. | documenti | documenti, documenti, documenti | 3/3 |
| 9 | follow-up della 1 | E per il pranzo? | 25,00 € in fascia A, invariato anche dal 1° luglio 2026. | documenti | documenti, documenti, documenti | 3/3 |
| | | | | | **totale** | **25/27** |

## 1. documenti

**Domanda:** Qual è il massimale per una cena in trasferta a Milano?

**Risposta vera:** 35,00 € per le cene fino al 30 giugno 2026, 40,00 € per quelle dal 1° luglio 2026 (Circolare n. 7/2026, che aggiorna l'art. 6 della Policy trasferte e note spese).

**Come è stata trovata:**

- `circolari-personale-2026.html`, chunk `circolari-personale-2026-019`: «dal 1° luglio 2026 il massimale di rimborso della cena nelle città di fascia A (Milano e Roma) passa da 35,00 € a 40,00 € per persona.»
- `circolari-personale-2026.html`, chunk `circolari-personale-2026-020`: «Per le cene fino al 30 giugno 2026 resta valido il massimale di 35,00 € previsto dall'art. 6 della Policy trasferte e note spese»

*Nota:* La tabella dell'art. 6 della Policy (chunk policy-trasferte-note-spese-012) dice ancora 35,00 €: una risposta che dà solo quel valore è incompleta.

### Lancio 1 · sessione `griglia-20261105-204813-d1-l1` · 6,2 s

**Strada:** documenti · **Citazioni:** `circolari-personale-2026-019` (circolari-personale-2026.html), `circolari-personale-2026-020` (circolari-personale-2026.html)

```text
cerca_documenti {"domanda": "Qual è il massimale per una cena in trasferta a Milano?"}
```

> A Milano, il massimale per la cena in trasferta è **40,00 € per persona**, per le cene consumate dal **1° luglio 2026** [circolari-personale-2026-019]. Fino al 30 giugno 2026 era di 35,00 € [circolari-personale-2026-020].

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-204813-d1-l2` · 4,5 s

**Strada:** documenti · **Citazioni:** `circolari-personale-2026-019` (circolari-personale-2026.html), `circolari-personale-2026-020` (circolari-personale-2026.html)

```text
cerca_documenti {"domanda": "Qual è il massimale per una cena in trasferta a Milano?"}
```

> Per una cena in trasferta a Milano, il massimale è di **40,00 € per persona**, per le cene consumate dal **1° luglio 2026** [circolari-personale-2026-019]. Fino al 30 giugno 2026 era di 35,00 € [circolari-personale-2026-020].

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-204813-d1-l3` · 4,2 s

**Strada:** documenti · **Citazioni:** `circolari-personale-2026-019` (circolari-personale-2026.html), `circolari-personale-2026-020` (circolari-personale-2026.html), `policy-trasferte-note-spese-003` (policy-trasferte-note-spese.docx)

```text
cerca_documenti {"domanda": "Qual è il massimale per una cena in trasferta a Milano?"}
```

> A Milano, il massimale per una cena in trasferta è di **40,00 € per persona**, per le cene consumate dal **1° luglio 2026** [circolari-personale-2026-019] [circolari-personale-2026-020]. Il limite comprende IVA, coperto e servizio [policy-trasferte-note-spese-003].

**Esito:** giusta — solo il massimale di oggi, con la data da cui vale

## 2. documenti

**Domanda:** Se sono in trasferta mi spetta il buono pasto?

**Risposta vera:** Sì, se la giornata di lavoro è di almeno 6 ore e non chiedi il rimborso del pranzo in nota spese; se lo chiedi, il buono non spetta. La cena rimborsata non conta.

**Come è stata trovata:**

- `regolamento-welfare-buoni-pasto.docx`, chunk `regolamento-welfare-buoni-pasto-006`: «Se il dipendente inserisce in nota spese il pranzo di quella giornata, il buono non spetta; se non chiede il rimborso del pranzo, il buono spetta purché la giornata lavorativa sia di almeno 6 ore. La cena rimborsata in trasferta non incide sul buono pasto.»

### Lancio 1 · sessione `griglia-20261105-204813-d2-l1` · 4,6 s

**Strada:** documenti · **Citazioni:** `regolamento-welfare-buoni-pasto-006` (regolamento-welfare-buoni-pasto.docx), `policy-trasferte-note-spese-009` (policy-trasferte-note-spese.docx)

```text
cerca_documenti {"domanda": "Durante una trasferta spetta il buono pasto? Regole sulla cumulabilità o sostituzione tra buono pasto e rimborso del pasto in trasferta."}
```

> Sì, ma non si cumula con il rimborso del pranzo: in trasferta il buono pasto spetta se non chiedi il rimborso del pranzo e la giornata lavorativa è di almeno 6 ore [regolamento-welfare-buoni-pasto-006]. Se inserisci il pranzo nella nota spese, il buono non spetta [policy-trasferte-note-spese-009].

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-204813-d2-l2` · 4,7 s

**Strada:** documenti · **Citazioni:** `regolamento-welfare-buoni-pasto-006` (regolamento-welfare-buoni-pasto.docx)

```text
cerca_documenti {"domanda": "Durante una trasferta spetta il buono pasto? Regole su buoni pasto e trasferte, eventuale cumulabilità o esclusione."}
```

> Sì, **se la giornata di trasferta dura almeno 6 ore e non chiedi il rimborso del pranzo**. Se inserisci il pranzo nella nota spese, il buono pasto non spetta; il rimborso della cena non incide sul buono pasto [regolamento-welfare-buoni-pasto-006].

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-204813-d2-l3` · 5,3 s

**Strada:** documenti · **Citazioni:** `regolamento-welfare-buoni-pasto-006` (regolamento-welfare-buoni-pasto.docx)

```text
cerca_documenti {"domanda": "Durante una trasferta spetta il buono pasto? Regole su buoni pasto e trasferte."}
```

> Sì, ma non se chiedi il rimborso del pranzo in nota spese. In trasferta, il buono pasto spetta se non chiedi il rimborso del pranzo e la giornata lavorativa dura almeno 6 ore; la cena rimborsata non incide sul buono pasto [regolamento-welfare-buoni-pasto-006].

**Esito:** giusta

## 3. documenti

**Domanda:** Sono appena stato assunto: entro quando devo fare i corsi sulla sicurezza?

**Risposta vera:** La formazione generale (4 ore) entro 30 giorni; la specifica dipende dal reparto: rischio basso (uffici e Commerciale, 4 ore) entro 60 giorni, rischio alto (Produzione e Logistica, 12 ore) prima di essere adibiti alla mansione.

**Come è stata trovata:**

- `procedura-ingresso-dotazioni.pdf`, chunk `procedura-ingresso-dotazioni-013`: «Sicurezza, formazione generale 4 ore e-learning entro 30 giorni dall'assunzione Sicurezza, formazione specifica rischio basso (uffici e Commerciale) 4 ore»
- `procedura-ingresso-dotazioni.pdf`, chunk `procedura-ingresso-dotazioni-014`: «e-learning o aula entro 60 giorni dall'assunzione Sicurezza, formazione specifica rischio alto (Produzione e Logistica) 12 ore aula prima di essere adibiti alla mansione»

*Nota:* La tabella dei corsi è divisa tra due chunk. Nella griglia del docente, con K 4, erano 6° e 8° nella ricerca: la domanda è passata solo con K 8.

### Lancio 1 · sessione `griglia-20261105-204813-d3-l1` · 5,0 s

**Strada:** documenti · **Citazioni:** `procedura-ingresso-dotazioni-013` (procedura-ingresso-dotazioni.pdf), `procedura-ingresso-dotazioni-014` (procedura-ingresso-dotazioni.pdf)

```text
cerca_documenti {"domanda": "Sono appena stato assunto: entro quando devo fare i corsi sulla sicurezza?"}
```

> I corsi sulla sicurezza vanno completati così:
>
> - **Formazione generale:** entro 30 giorni dall’assunzione.
> - **Formazione specifica a rischio basso** (uffici e Commerciale): entro 60 giorni.
> - **Formazione specifica a rischio alto** (Produzione e Logistica): prima di essere adibito alla mansione. [procedura-ingresso-dotazioni-013] [procedura-ingresso-dotazioni-014]

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-204813-d3-l2` · 5,1 s

**Strada:** documenti · **Citazioni:** `procedura-ingresso-dotazioni-012` (procedura-ingresso-dotazioni.pdf), `procedura-ingresso-dotazioni-013` (procedura-ingresso-dotazioni.pdf), `procedura-ingresso-dotazioni-014` (procedura-ingresso-dotazioni.pdf)

```text
cerca_documenti {"domanda": "Sono appena stato assunto: entro quando devo fare i corsi sulla sicurezza?"}
```

> I corsi sulla sicurezza vanno completati entro:
>
> - **Formazione generale:** 30 giorni dall’assunzione.
> - **Formazione specifica per uffici e Commerciale (rischio basso):** 60 giorni dall’assunzione.
> - **Formazione specifica per Produzione e Logistica (rischio alto):** prima di essere assegnato alla mansione.
>
> L’iscrizione viene effettuata dall’Ufficio del Personale. [procedura-ingresso-dotazioni-012] [procedura-ingresso-dotazioni-013] [procedura-ingresso-dotazioni-014]

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-204813-d3-l3` · 5,6 s

**Strada:** documenti · **Citazioni:** `procedura-ingresso-dotazioni-012` (procedura-ingresso-dotazioni.pdf), `procedura-ingresso-dotazioni-013` (procedura-ingresso-dotazioni.pdf), `procedura-ingresso-dotazioni-014` (procedura-ingresso-dotazioni.pdf)

```text
cerca_documenti {"domanda": "Sono appena stato assunto: entro quando devo completare i corsi sulla sicurezza?"}
```

> - **Formazione generale sulla sicurezza:** entro 30 giorni dall’assunzione.
> - **Formazione specifica:** entro 60 giorni se lavori in ufficio o Commerciale; **prima di essere adibito alla mansione** se lavori in Produzione o Logistica. [procedura-ingresso-dotazioni-012] [procedura-ingresso-dotazioni-013] [procedura-ingresso-dotazioni-014]

**Esito:** giusta

## 4. dati

**Domanda:** Quanti giorni di ferie ha preso Sara Conti nel 2026?

**Risposta vera:** Le Sara Conti sono due: 15 giorni quella di Brescia (Commerciale) e 21 quella di Padova (Logistica). Il totale 36 non riguarda nessuna delle due.

**Come è stata trovata:**

- `SELECT d.id, d.sede, d.reparto, SUM(a.quantita) AS giorni FROM dipendenti d JOIN assenze a ON a.dipendente_id = d.id WHERE d.nome = 'Sara' AND d.cognome = 'Conti' AND a.tipo = 'ferie' AND a.data_inizio BETWEEN '2026-01-01' AND '2026-12-31' GROUP BY d.id ORDER BY d.id` → `[[17, "Brescia", "Commerciale", 15], [88, "Padova", "Logistica", 21]]`

### Lancio 1 · sessione `griglia-20261105-204813-d4-l1` · 9,2 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT d.nome, d.cognome, COALESCE(SUM(a.quantita), 0) AS giorni_ferie_2026 FROM dipendenti d LEFT JOIN assenze a ON a.dipendente_id = d.id AND a.tipo = 'ferie' AND a.unita = 'giorni' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01' WHERE d.nome = 'Sara' AND d.cognome = 'Conti' GROUP BY d.id, d.nome, d.cognome"}
query_db {"sql": "SELECT d.id AS dipendente_id, d.nome, d.cognome, d.sede, d.reparto, SUM(a.quantita) AS giorni_ferie_2026 FROM dipendenti d JOIN assenze a ON a.dipendente_id = d.id WHERE d.nome = 'Sara' AND d.cognome = 'Conti' AND a.tipo = 'ferie' AND a.unita = 'giorni' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01' GROUP BY d.id, d.nome, d.cognome, d.sede, d.reparto"}
```

> Risultano due dipendenti di nome Sara Conti:
>
> - Sara Conti, sede Brescia, Commerciale: **15 giorni**
> - Sara Conti, sede Padova, Logistica: **21 giorni**

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-204813-d4-l2` · 8,2 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT d.nome, d.cognome, a.tipo, a.data_inizio, a.data_fine, a.quantita, a.unita FROM assenze a JOIN dipendenti d ON d.id = a.dipendente_id WHERE d.nome = 'Sara' AND d.cognome = 'Conti' AND a.tipo = 'ferie' AND a.unita = 'giorni' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01' ORDER BY a.data_inizio"}
```

> Sara Conti ha preso **36 giorni di ferie nel 2026**.

**Esito:** sbagliata — «36 giorni»: somma le due Sara Conti

### Lancio 3 · sessione `griglia-20261105-204813-d4-l3` · 8,6 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT d.nome, d.cognome, COALESCE(SUM(a.quantita), 0) AS giorni_ferie_2026 FROM dipendenti d LEFT JOIN assenze a ON a.dipendente_id = d.id AND a.tipo = 'ferie' AND a.unita = 'giorni' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01' WHERE d.nome = 'Sara' AND d.cognome = 'Conti' GROUP BY d.id, d.nome, d.cognome"}
query_db {"sql": "SELECT d.id AS dipendente_id, d.nome, d.cognome, a.data_inizio, a.data_fine, a.quantita, a.unita FROM dipendenti d JOIN assenze a ON a.dipendente_id = d.id WHERE d.nome = 'Sara' AND d.cognome = 'Conti' AND a.tipo = 'ferie' AND a.unita = 'giorni' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01' ORDER BY d.id, a.data_inizio"}
```

> Risultano due dipendenti omonime: Sara Conti **ID 17 ha preso 15 giorni**, mentre Sara Conti **ID 88 ne ha presi 21**. In totale: **36 giorni di ferie nel 2026**.

**Esito:** sbagliata — dà 15 e 21, poi le somma in «36 giorni in totale»

## 5. dati

**Domanda:** Quante note spese sono ancora in attesa di approvazione, e per quale importo?

**Risposta vera:** 56 note spese, per 2.184,40 € in tutto.

**Come è stata trovata:**

- `SELECT COUNT(*) AS note, ROUND(SUM(importo), 2) AS importo FROM note_spese WHERE stato = 'in attesa'` → `[[56, 2184.4]]`

*Nota:* Nella tabella lo stato è «in attesa»: una query con stato = 'in attesa di approvazione' trova 0 righe.

### Lancio 1 · sessione `griglia-20261105-204813-d5-l1` · 4,2 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COUNT(*) AS numero_note_spese, COALESCE(SUM(importo), 0) AS importo_totale FROM note_spese WHERE stato = 'in attesa';"}
```

> Sono **56 note spese** ancora in attesa di approvazione, per un importo complessivo di **2.184,40 €**.

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-204813-d5-l2` · 4,0 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COUNT(*) AS numero_note_spese, COALESCE(SUM(importo), 0) AS importo_totale FROM note_spese WHERE stato = 'in attesa';"}
```

> Ci sono **56 note spese** ancora in attesa di approvazione, per un importo complessivo di **2.184,40 €**.

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-204813-d5-l3` · 3,9 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COUNT(*) AS numero_note, COALESCE(SUM(importo), 0) AS importo_totale FROM note_spese WHERE stato = 'in attesa'"}
```

> Sono **56 note spese** ancora in attesa di approvazione, per un importo complessivo di **2.184,40 €**.

**Esito:** giusta

## 6. dati

**Domanda:** Quante ore di permesso hanno preso i dipendenti del reparto IT nel 2026?

**Risposta vera:** 31 ore, in 10 permessi.

**Come è stata trovata:**

- `SELECT SUM(a.quantita) AS ore, COUNT(*) AS permessi FROM assenze a JOIN dipendenti d ON d.id = a.dipendente_id WHERE d.reparto = 'IT' AND a.tipo = 'permesso' AND a.data_inizio BETWEEN '2026-01-01' AND '2026-12-31'` → `[[31, 10]]`

### Lancio 1 · sessione `griglia-20261105-204813-d6-l1` · 4,4 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COALESCE(SUM(a.quantita), 0) AS totale_ore_permesso FROM assenze a JOIN dipendenti d ON d.id = a.dipendente_id WHERE d.reparto = 'IT' AND a.tipo = 'permesso' AND a.unita = 'ore' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01';"}
```

> Nel 2026, i dipendenti del reparto IT hanno preso complessivamente **31 ore di permesso**.

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-204813-d6-l2` · 4,4 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COALESCE(SUM(a.quantita), 0) AS totale_ore_permesso FROM assenze a JOIN dipendenti d ON d.id = a.dipendente_id WHERE d.reparto = 'IT' AND a.tipo = 'permesso' AND a.unita = 'ore' AND a.data_inizio >= '2026-01-01' AND a.data_inizio < '2027-01-01';"}
```

> Nel 2026 i dipendenti del reparto IT hanno preso complessivamente **31 ore di permesso**.

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-204813-d6-l3` · 5,4 s

**Strada:** dati · **Citazioni:** nessuna

```text
query_db {"sql": "SELECT COALESCE(SUM(a.quantita), 0) AS totale_ore_permesso\nFROM assenze a\nJOIN dipendenti d ON d.id = a.dipendente_id\nWHERE d.reparto = 'IT'\n  AND a.tipo = 'permesso'\n  AND a.unita = 'ore'\n  AND a.data_inizio >= '2026-01-01'\n  AND a.data_inizio < '2027-01-01';"}
```

> Nel 2026, i dipendenti del reparto IT hanno preso **31 ore di permesso**.

**Esito:** giusta

## 7. documenti+dati

**Domanda:** La nota spese 1043 rispetta il massimale?

**Risposta vera:** Sì: è una cena a Milano del 15 luglio 2026 da 38,50 €, e per le cene in fascia A dal 1° luglio 2026 il massimale è 40,00 € (Circolare n. 7/2026). Con il massimale precedente, 35,00 €, lo supererebbe.

**Come è stata trovata:**

- `SELECT id, data, citta, voce, importo, stato FROM note_spese WHERE id = 1043` → `[[1043, "2026-07-15", "Milano", "cena", 38.5, "in attesa"]]`
- `circolari-personale-2026.html`, chunk `circolari-personale-2026-019`: «dal 1° luglio 2026 il massimale di rimborso della cena nelle città di fascia A (Milano e Roma) passa da 35,00 € a 40,00 € per persona.»
- `policy-trasferte-note-spese.docx`, chunk `policy-trasferte-note-spese-014`: «Il massimale da applicare è quello in vigore alla data in cui la spesa è stata sostenuta»

### Lancio 1 · sessione `griglia-20261105-204813-d7-l1` · 7,7 s

**Strada:** documenti+dati · **Citazioni:** `circolari-personale-2026-019` (circolari-personale-2026.html), `circolari-personale-2026-021` (circolari-personale-2026.html)

```text
query_db {"sql": "SELECT id, dipendente_id, data, citta, voce, importo, stato FROM note_spese WHERE id = 1043"}
cerca_documenti {"domanda": "massimali applicabili alle note spese per voce di spesa (cena, hotel, km, pranzo, treno) e verifica del rispetto del massimale"}
cerca_documenti {"domanda": "massimale cena dal 1 luglio 2026 Milano"}
```

> Sì. La nota spese **1043** è una cena a Milano del **15 luglio 2026**, pari a **38,50 €**; il massimale vigente è **40,00 €**, quindi lo rispetta. [circolari-personale-2026-019] [circolari-personale-2026-021]

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-204813-d7-l2` · 9,1 s

**Strada:** documenti+dati · **Citazioni:** `circolari-personale-2026-019` (circolari-personale-2026.html), `circolari-personale-2026-021` (circolari-personale-2026.html)

```text
query_db {"sql": "SELECT id, dipendente_id, data, citta, voce, importo, stato FROM note_spese WHERE id = 1043"}
cerca_documenti {"domanda": "massimali applicabili alle note spese e criteri per verificare se una nota spese rispetta il massimale"}
cerca_documenti {"domanda": "articolo 6 massimale cena Milano fascia A 15 luglio 2026 importo massimo"}
```

> Sì. La nota spese **1043**, relativa a una cena a Milano del **15 luglio 2026** per **38,50 €**, rispetta il massimale di **40,00 €** in vigore dal 1° luglio 2026 per le città di fascia A [circolari-personale-2026-019] [circolari-personale-2026-021]. Attualmente risulta **in attesa**.

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-204813-d7-l3` · 7,9 s

**Strada:** documenti+dati · **Citazioni:** `circolari-personale-2026-019` (circolari-personale-2026.html), `circolari-personale-2026-021` (circolari-personale-2026.html)

```text
query_db {"sql": "SELECT id, dipendente_id, data, citta, voce, importo, stato FROM note_spese WHERE id = 1043"}
cerca_documenti {"domanda": "massimali applicabili alle note spese per voce (cena, hotel, km, pranzo, treno) e verifica del rispetto del massimale"}
cerca_documenti {"domanda": "Articolo 6 massimale cena Milano 2026 dal 1 luglio 2026"}
```

> Sì. La nota spese **1043** riguarda una cena a Milano del **15 luglio 2026**, pari a **38,50 €**; il massimale vigente per la cena in fascia A è **40,00 €**, quindi è rispettato. [circolari-personale-2026-019] [circolari-personale-2026-021]

**Esito:** giusta

## 8. senza risposta

**Domanda:** Quando viene pagato il premio di produzione?

**Risposta vera:** Non c'è: nessun documento e nessuna tabella parlano di un premio di produzione. La risposta giusta dice che non l'ha trovato e dove ha cercato.

**Come è stata trovata:**

- «premio» non compare in nessun documento né tabella

### Lancio 1 · sessione `griglia-20261105-204813-d8-l1` · 4,2 s

**Strada:** documenti · **Citazioni:** nessuna

```text
cerca_documenti {"domanda": "Quando viene pagato il premio di produzione? Indicazioni su scadenza, mese o modalità di pagamento."}
```

> Non ho trovato informazioni sul pagamento del premio di produzione nei documenti consultati. Ho cercato in **regolamento-welfare-buoni-pasto.docx**, **policy-trasferte-note-spese.docx** e **faq-portale-dipendenti.md**.

**Esito:** rifiuto giusto

### Lancio 2 · sessione `griglia-20261105-204813-d8-l2` · 4,8 s

**Strada:** documenti · **Citazioni:** nessuna

```text
cerca_documenti {"domanda": "Quando viene pagato il premio di produzione? Indicazioni su periodo e modalità di pagamento."}
```

> Non ho trovato informazioni sul pagamento del premio di produzione nei documenti consultati.

**Esito:** rifiuto giusto — ma non dice in quali documenti ha cercato

### Lancio 3 · sessione `griglia-20261105-204813-d8-l3` · 3,8 s

**Strada:** documenti · **Citazioni:** nessuna

```text
cerca_documenti {"domanda": "Quando viene pagato il premio di produzione?"}
```

> Non ho trovato informazioni sul pagamento del premio di produzione nei documenti consultati.

**Esito:** rifiuto giusto — ma non dice in quali documenti ha cercato

## 9. follow-up della 1

**Domanda:** E per il pranzo?

**Risposta vera:** 25,00 € in fascia A, invariato anche dal 1° luglio 2026.

**Come è stata trovata:**

- `circolari-personale-2026.html`, chunk `circolari-personale-2026-019`: «Il massimale del pranzo in fascia A resta di 25,00 €.»
- `policy-trasferte-note-spese.docx`, chunk `policy-trasferte-note-spese-011`: «Voce: Pranzo; Fascia A (Milano, Roma): 25,00 €»

### Lancio 1 · sessione `griglia-20261105-204813-d1-l1` · 4,7 s

**Strada:** documenti · **Citazioni:** `circolari-personale-2026-019` (circolari-personale-2026.html), `policy-trasferte-note-spese-009` (policy-trasferte-note-spese.docx)

```text
cerca_documenti {"domanda": "Qual è il massimale per il pranzo in trasferta a Milano, fascia A?"}
```

> Per il pranzo a Milano il massimale è **25,00 € per persona al giorno** [circolari-personale-2026-019]. Il pranzo è rimborsabile se la trasferta comprende la fascia **12:00–14:30** [policy-trasferte-note-spese-009].

**Esito:** giusta

### Lancio 2 · sessione `griglia-20261105-204813-d1-l2` · 4,1 s

**Strada:** documenti · **Citazioni:** `policy-trasferte-note-spese-011` (policy-trasferte-note-spese.docx), `policy-trasferte-note-spese-009` (policy-trasferte-note-spese.docx)

```text
cerca_documenti {"domanda": "Qual è il massimale per il pranzo in trasferta a Milano?"}
```

> Per il pranzo in trasferta a Milano, il massimale è di **25,00 € al giorno per persona** [policy-trasferte-note-spese-011]. Il rimborso è previsto se la trasferta comprende la fascia oraria **12:00–14:30** [policy-trasferte-note-spese-009].

**Esito:** giusta

### Lancio 3 · sessione `griglia-20261105-204813-d1-l3` · 4,6 s

**Strada:** documenti · **Citazioni:** `policy-trasferte-note-spese-011` (policy-trasferte-note-spese.docx), `policy-trasferte-note-spese-009` (policy-trasferte-note-spese.docx)

```text
cerca_documenti {"domanda": "Qual è il massimale per il pranzo in trasferta a Milano?"}
```

> A Milano, il massimale per il pranzo in trasferta è di **25,00 € al giorno per persona** [policy-trasferte-note-spese-011]. Il rimborso è previsto se la trasferta comprende la fascia oraria **12:00–14:30** [policy-trasferte-note-spese-009].

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
