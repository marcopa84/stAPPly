"""Le tabelle di un agente: ogni file CSV o Excel diventa una tabella del suo tables.db, che
query_db e la console SQL interrogano in sola lettura.

`tipo()` e `salva()` vengono da scaffolding/tabelle_in_db.py (corso), portati da uno script
su una cartella a funzioni su un singolo file caricato.
"""

import csv
import io
import itertools
import json
import logging
import math
import os
import re
import sqlite3
import time
from collections.abc import Sequence
from contextlib import closing
from datetime import date, datetime, timedelta
from datetime import time as orario
from pathlib import Path
from typing import Any

from openpyxl import load_workbook

from app.config import get_settings
from app.core import firme, ids, storage
from app.core.errors import (
    ContenutoNonValido,
    ErroreStapply,
    FileTroppoGrande,
    LimiteRaggiunto,
    NonTrovato,
)

logger = logging.getLogger(__name__)

TEMPO_MASSIMO_QUERY = 5.0  # secondi: oltre, SQLite interrompe la query
_ISTRUZIONI_TRA_CONTROLLI = 10_000  # ogni quante istruzioni della VM di SQLite si guarda l'ora
# Colonne di una tabella importata e di un risultato. Un solo valore per tutti e due: SQLite
# applica il limite delle query anche quando rilegge lo schema, quindi una tabella più larga
# del limite non si potrebbe più interrogare.
MAX_COLONNE = 500
# Righe × colonne di una tabella importata: un CSV da 10 MB ne ha di solito meno di un milione.
# Le righe corte si allungano fino all'intestazione, quindi senza tetto poche righe di
# separatori diventerebbero miliardi di celle in memoria.
MAX_CELLE = 2_000_000
# Byte di un testo o di un blob prodotto da una query: più del campo più lungo che il modulo
# csv accetta (131 072 caratteri). Una riga intera pesa al massimo MAX_COLONNE volte tanto.
_MAX_LUNGHEZZA_VALORE = 200_000
# Caratteri di un risultato (i blob contano in esadecimale, come escono in JSON): oltre, la
# query è rifiutata invece di riempire la memoria del backend e della risposta a Laravel.
MAX_CARATTERI_RISULTATO = 1_000_000
_CANDIDATI_SEPARATORE = (",", ";")
_RECORD_PER_IL_SEPARATORE = 20

# Prima della parola chiave possono esserci spazi e commenti.
_PREAMBOLO_SQL = re.compile(r"(?:\s+|--[^\n]*(?:\n|$)|/\*.*?\*/)*", re.DOTALL)
_AZIONI_DI_LETTURA = {
    sqlite3.SQLITE_SELECT,
    sqlite3.SQLITE_READ,
    sqlite3.SQLITE_FUNCTION,
    sqlite3.SQLITE_RECURSIVE,
}

# Rispetto a int() e float() del corso: niente zeri iniziali (un CAP o un codice "007" restano
# testo), niente "1_000", "nan" o "inf", e gli interi entro i 64 bit di SQLite.
_INTERO = re.compile(r"[+-]?(?:0|[1-9][0-9]{0,17})")
_DECIMALE = re.compile(r"[+-]?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?")
# I numeri come li scrive Excel in italiano: virgola per i decimali, punto per le migliaia
# a gruppi di tre ("4,50", "1.234,50", "-12,3"). Una data come "15.07.2026" non ci rientra.
_NUMERO_ITALIANO = re.compile(
    r"[+-]?(?:0|[1-9][0-9]{0,2}(?:\.[0-9]{3})+|[1-9][0-9]*)(?:,[0-9]+)?(?:[eE][+-]?[0-9]+)?"
)

Riga = list[Any]


def tipo(valori: Sequence[object]) -> str:
    """INTEGER se sono tutti interi, REAL se sono tutti numeri, altrimenti TEXT (le celle
    vuote non contano)."""
    pieni = [str(v) for v in valori if v is not None]
    if not pieni:
        return "TEXT"  # colonna vuota: nessun numero da proteggere
    if all(_INTERO.fullmatch(v) for v in pieni):
        return "INTEGER"
    if all(_DECIMALE.fullmatch(v) for v in pieni):
        return "REAL"
    return "TEXT"


def salva(
    percorso: Path, nome: str, colonne: list[str], tipi: list[str], righe: list[Riga]
) -> None:
    """Una tabella nel database: DROP, CREATE e INSERT in un'unica transazione, così una
    sostituzione che fallisce lascia al suo posto la tabella di prima."""
    definizione = ", ".join(f"{_quota(c)} {t}" for c, t in zip(colonne, tipi, strict=True))
    segnaposto = ", ".join("?" for _ in colonne)
    try:
        # autocommit=False: anche DROP e CREATE stanno nella transazione (nel modo legacy di
        # sqlite3 no) e chiudere senza commit annulla tutto.
        with closing(sqlite3.connect(percorso, autocommit=False)) as con:
            con.execute(f"DROP TABLE IF EXISTS {_quota(nome)}")
            con.execute(f"CREATE TABLE {_quota(nome)} ({definizione})")
            con.executemany(f"INSERT INTO {_quota(nome)} VALUES ({segnaposto})", righe)
            con.commit()
    except (sqlite3.Error, OverflowError) as errore:
        raise ContenutoNonValido(f"La tabella non si può salvare: {errore}.") from errore


def importa_tabella(agent_id: str, filename: str, contenuto: bytes) -> dict[str, Any]:
    """Importa un CSV o il primo foglio di un .xlsx come tabella dell'agente. Una tabella con
    lo stesso nome viene sostituita.

    Restituisce le chiavi di docs/contract/tables.upload.201.json. Solleva FileNonAmmesso,
    FileTroppoGrande (anche oltre MAX_CELLE), LimiteRaggiunto o ContenutoNonValido, e
    NonTrovato se l'agente viene cancellato mentre il file si legge.
    """
    nome_file = ids.nome_file(filename)
    ext = firme.controlla_tabella(nome_file, contenuto)
    tabella = ids.nome_tabella(nome_file)
    cancellazioni = storage.cancellazioni(agent_id)
    intestazione, righe = _leggi_csv(contenuto) if ext == ".csv" else _leggi_xlsx(contenuto)
    colonne, righe = _prepara(intestazione, righe)
    tipi = [tipo([riga[i] for riga in righe]) for i in range(len(colonne))]
    valori = [[_converti(v, t) for v, t in zip(riga, tipi, strict=True)] for riga in righe]

    percorso = storage.percorso_tabelle(agent_id)
    with storage.blocco_agente(agent_id):
        # Prima di mkdir, che ricreerebbe la cartella di un agente cancellato nel frattempo.
        storage.controlla_non_cancellato(agent_id, cancellazioni)
        presenti = _nomi_tabelle(percorso)
        sostituita = tabella in presenti
        massimo = get_settings().max_tables_per_agent
        if not sostituita and len(presenti) >= massimo:
            raise LimiteRaggiunto(
                f"Limite di {massimo} tabelle per agente raggiunto: cancellane una, oppure "
                "carica un file con lo stesso nome per sostituirla."
            )
        percorso.parent.mkdir(parents=True, exist_ok=True)
        salva(percorso, tabella, colonne, tipi, valori)
        uploaded_at = storage.adesso()
        meta = _leggi_meta(agent_id)
        meta[tabella] = {"filename": nome_file, "uploaded_at": uploaded_at}
        _scrivi_meta(agent_id, meta)

    logger.info(
        "%s: %s importato nella tabella %s (%d righe)", agent_id, nome_file, tabella, len(valori)
    )
    return {
        "table": tabella,
        "filename": nome_file,
        "rows": len(valori),
        "columns": [{"name": c, "type": t} for c, t in zip(colonne, tipi, strict=True)],
        "uploaded_at": uploaded_at,
        "replaced": sostituita,
    }


def lista_tabelle(agent_id: str) -> list[dict[str, Any]]:
    """Le tabelle dell'agente ordinate per nome, con le chiavi di
    docs/contract/tables.list.200.json. Lista vuota per un agente mai visto."""
    percorso = storage.percorso_tabelle(agent_id)
    if not percorso.exists():
        return []
    meta = _leggi_meta(agent_id)
    tabelle = []
    with closing(_connetti_sola_lettura(percorso)) as con:
        for nome in _nomi(con):
            info = meta.get(nome, {})
            colonne = con.execute(f"PRAGMA table_info({_quota(nome)})").fetchall()
            tabelle.append(
                {
                    "table": nome,
                    "filename": info.get("filename", ""),
                    "rows": con.execute(f"SELECT COUNT(*) FROM {_quota(nome)}").fetchone()[0],
                    "columns": [{"name": c[1], "type": c[2]} for c in colonne],
                    "uploaded_at": info.get("uploaded_at", ""),
                }
            )
    return tabelle


def ha_tabelle(agent_id: str) -> bool:
    """True se l'agente ha almeno una tabella (solo allora ha senso il tool query_db)."""
    return bool(_nomi_tabelle(storage.percorso_tabelle(agent_id)))


def copia_tabelle(sorgente: str, destinazione: str) -> int:
    """Copia in `destinazione` il tables.db e i metadati delle tabelle di `sorgente`;
    restituisce quante tabelle ha copiato.

    Il database si copia con l'API di backup di SQLite, che legge una fotografia coerente,
    non con una copia del file, che potrebbe essere aperto. Chi la chiama tiene il blocco di
    tutti e due gli agenti e, se fallisce, ripulisce la destinazione (app/core/clone.py).
    """
    origine = storage.percorso_tabelle(sorgente)
    nomi = _nomi_tabelle(origine)
    if not nomi:
        return 0
    arrivo = storage.percorso_tabelle(destinazione)
    arrivo.parent.mkdir(parents=True, exist_ok=True)
    with closing(_connetti_sola_lettura(origine)) as da, closing(sqlite3.connect(arrivo)) as a:
        da.backup(a)
    _scrivi_meta(destinazione, _leggi_meta(sorgente))
    return len(nomi)


def cancella_tabella(agent_id: str, table: str) -> None:
    """Toglie una tabella dell'agente. NonTrovato se non c'è."""
    percorso = storage.percorso_tabelle(agent_id)
    with storage.blocco_agente(agent_id):
        # Il nome arriva dall'URL: si confronta con le tabelle vere prima di usarlo in SQL.
        if table not in _nomi_tabelle(percorso):
            raise NonTrovato(f"La tabella «{table}» non esiste.")
        with closing(sqlite3.connect(percorso, autocommit=False)) as con:
            con.execute(f"DROP TABLE {_quota(table)}")
            con.commit()
        meta = _leggi_meta(agent_id)
        meta.pop(table, None)
        _scrivi_meta(agent_id, meta)


def schema_testo(agent_id: str) -> str:
    """Una riga `tabella(colonna TIPO, ...)` per tabella: va nella descrizione di query_db,
    così il modello scrive le query sui nomi veri. Stringa vuota se non ci sono tabelle."""
    righe = []
    for tabella in lista_tabelle(agent_id):
        colonne = ", ".join(f"{c['name']} {c['type']}" for c in tabella["columns"])
        righe.append(f"{tabella['table']}({colonne})")
    return "\n".join(righe)


def esegui_select(
    agent_id: str,
    sql: str,
    limite: int,
    tempo_massimo: float = TEMPO_MASSIMO_QUERY,
    max_caratteri: int = MAX_CARATTERI_RISULTATO,
) -> dict[str, Any]:
    """Esegue una query di sola lettura sulle tabelle dell'agente.

    Restituisce {"columns", "rows", "truncated"} con al massimo `limite` righe. Le difese sono
    a strati: solo SELECT o WITH, una sola istruzione (la impone sqlite3), connessione
    `mode=ro`, `PRAGMA query_only`, un authorizer che ammette solo letture, un limite di
    tempo e uno sulla dimensione: colonne, valori e `max_caratteri` per tutto il risultato
    (zeroblob() costa un'istruzione sola e produce un megabyte). NonTrovato se l'agente non
    ha tabelle; ContenutoNonValido, con il messaggio di SQLite, se la query è rifiutata o
    fallisce: serve al modello per correggerla.
    """
    percorso = storage.percorso_tabelle(agent_id)
    if not _nomi_tabelle(percorso):
        raise NonTrovato("L'agente non ha tabelle: carica prima un file CSV o Excel.")
    if not _inizia_con_select(sql):
        raise ContenutoNonValido(
            "Sono ammesse solo query di lettura: SELECT, oppure WITH … SELECT."
        )

    scadenza = time.monotonic() + tempo_massimo
    scaduta = False
    negate: list[int] = []

    def controlla_tempo() -> int:
        nonlocal scaduta
        scaduta = time.monotonic() > scadenza
        return int(scaduta)  # un valore diverso da zero interrompe la query

    def autorizza(azione: int, *_: str | None) -> int:
        if azione in _AZIONI_DI_LETTURA:
            return sqlite3.SQLITE_OK
        negate.append(azione)
        return sqlite3.SQLITE_DENY

    righe: list[list[object]] = []
    troncato = False
    try:
        with closing(_connetti_sola_lettura(percorso)) as con:
            con.execute("PRAGMA query_only = ON")
            con.setlimit(sqlite3.SQLITE_LIMIT_LENGTH, _MAX_LUNGHEZZA_VALORE)
            con.setlimit(sqlite3.SQLITE_LIMIT_COLUMN, MAX_COLONNE)
            con.set_authorizer(autorizza)
            con.set_progress_handler(controlla_tempo, _ISTRUZIONI_TRA_CONTROLLI)
            cursore = con.execute(sql)
            colonne = [d[0] for d in cursore.description or []]
            caratteri = sum(len(c) for c in colonne)
            for riga in cursore:
                if len(righe) == limite:
                    troncato = True
                    break
                valori = []
                for valore in riga:
                    valori.append(_valore_json(valore))
                    caratteri += len(str(valori[-1]))
                    if caratteri > max_caratteri:
                        raise ContenutoNonValido(
                            f"Risultato troppo grande (oltre {max_caratteri} caratteri): "
                            "seleziona meno colonne o aggiungi un LIMIT."
                        )
                righe.append(valori)
    except sqlite3.Error as errore:
        if scaduta:
            messaggio = (
                f"La query è stata interrotta dopo {tempo_massimo:g} secondi: "
                "semplificala o aggiungi un LIMIT."
            )
        elif negate:
            messaggio = f"Query rifiutata: sono ammesse solo letture dei dati ({errore})."
        else:
            messaggio = f"Query non eseguita: {errore}"
        raise ContenutoNonValido(messaggio) from errore

    return {"columns": colonne, "rows": righe, "truncated": troncato}


def _inizia_con_select(sql: str) -> bool:
    preambolo = _PREAMBOLO_SQL.match(sql)
    resto = sql[preambolo.end() :] if preambolo else sql
    return re.match(r"(?:select|with)\b", resto, re.IGNORECASE) is not None


def _valore_json(valore: object) -> object:
    """Un valore che si può mettere in JSON: i blob in esadecimale, ±inf come testo."""
    if isinstance(valore, bytes):
        return valore.hex()
    if isinstance(valore, float) and not math.isfinite(valore):
        return str(valore)
    return valore


def _leggi_csv(contenuto: bytes) -> tuple[Riga, list[tuple[int, Riga]]]:
    """Intestazione e righe (con il loro numero nel file); celle vuote → None. Con il punto e
    virgola come separatore, i numeri scritti all'italiana diventano numeri."""
    testo = firme.testo(contenuto, ".csv")  # UTF-8, oppure Windows-1252 come lo salva Excel
    righe: list[tuple[int, Riga]] = []
    celle = 0
    try:
        separatore = _separatore(testo)
        lettore = csv.reader(io.StringIO(testo, newline=""), delimiter=separatore)
        for riga in lettore:
            celle = _conta_celle(celle, len(riga))
            valori = [v.strip() or None for v in riga]
            if any(v is not None for v in valori):  # righe vuote: si saltano
                righe.append((lettore.line_num, valori))
    except csv.Error as errore:
        raise ContenutoNonValido(f"Il CSV non si riesce a leggere: {errore}.") from errore
    if not righe:
        raise ContenutoNonValido("Il file è vuoto: manca la riga di intestazione.")
    dati = righe[1:]
    # Il punto e virgola è il separatore di Excel dove la virgola serve ai decimali.
    if separatore == ";":
        dati = _numeri_all_italiana(dati)
    return righe[0][1], dati


def _separatore(testo: str) -> str:
    """La virgola o il punto e virgola, scelto sui primi record del file: record e non righe,
    così un'intestazione tra virgolette con un a capo (Alt+Invio in Excel) non inganna.

    In ordine vince il separatore che divide tutti i record nello stesso numero di campi, più
    di uno; poi quello che divide di più l'intestazione; poi quello che dà a tutti i record lo
    stesso numero di campi (una sola colonna di numeri all'italiana, «1,5»). A parità, il punto
    e virgola se c'è nel testo: è il separatore di Excel in italiano, dove la virgola sta nei
    decimali e nei testi («Cognome, nome;Importo»).
    """

    def punteggio(separatore: str) -> tuple[bool, int, bool, bool]:
        lettore = csv.reader(io.StringIO(testo, newline=""), delimiter=separatore)
        pieni = (r for r in lettore if any(campo.strip() for campo in r))
        campi = [len(r) for r in itertools.islice(pieni, _RECORD_PER_IL_SEPARATORE)] or [0]
        coerente = len(set(campi)) == 1
        return (coerente and campi[0] > 1, campi[0], coerente, separatore == ";" and ";" in testo)

    return max(_CANDIDATI_SEPARATORE, key=punteggio)


def _conta_celle(celle: int, nuove: int) -> int:
    """Il conto delle celle lette fin qui; FileTroppoGrande oltre MAX_CELLE."""
    celle += nuove
    if celle > MAX_CELLE:
        massimo = f"{MAX_CELLE:,}".replace(",", ".")  # 2.000.000, all'italiana
        raise FileTroppoGrande(
            f"La tabella è troppo grande: al massimo {massimo} celle (righe × colonne)."
        )
    return celle


def _numeri_all_italiana(righe: list[tuple[int, Riga]]) -> list[tuple[int, Riga]]:
    """Nelle colonne in cui OGNI valore non vuoto è un numero all'italiana, lo riscrive come
    lo legge `tipo()`: "1.234,50" → "1234.50". Le altre colonne restano com'erano.

    Una colonna si legge tutta in un modo: "1.234" accanto a "12.5" resta 1.234 (punto
    decimale), accanto a "4,50" diventa 1234 (punto delle migliaia). Un codice come "007"
    non è un numero (niente zeri iniziali), quindi la sua colonna resta testo.
    """
    larghezza = max((len(riga) for _, riga in righe), default=0)
    italiane = {
        i
        for i in range(larghezza)
        if all(
            _NUMERO_ITALIANO.fullmatch(riga[i])
            for _, riga in righe
            if i < len(riga) and riga[i] is not None
        )
    }
    return [
        (
            numero,
            [
                v.replace(".", "").replace(",", ".") if i in italiane and v is not None else v
                for i, v in enumerate(riga)
            ],
        )
        for numero, riga in righe
    ]


def _leggi_xlsx(contenuto: bytes) -> tuple[Riga, list[tuple[int, Riga]]]:
    """Come `_leggi_csv`, dal primo foglio: le date diventano testo ISO (2026-07-15)."""
    righe: list[tuple[int, Riga]] = []
    celle = 0
    try:
        # data_only: i valori calcolati delle formule, non il testo "=SOMMA(...)".
        libro = load_workbook(io.BytesIO(contenuto), read_only=True, data_only=True)
        try:
            # worksheets[0] e non .active: il foglio attivo è l'ultimo aperto, non il primo.
            foglio = libro.worksheets[0]
            # In sola lettura openpyxl si fida del tag <dimension> del foglio, che può mentire:
            # più piccolo dei dati perde righe, enorme (A1:XFD…) allunga ogni riga a 16 384
            # celle. Senza, ogni riga è lunga fino alla sua ultima cella.
            foglio.reset_dimensions()
            for numero, riga in enumerate(foglio.iter_rows(values_only=True), 1):
                # Prima di convertire: anche una sola cella in colonna XFD allunga la riga.
                celle = _conta_celle(celle, len(riga))
                valori = [_valore_xlsx(v) for v in riga]
                if any(v is not None for v in valori):
                    righe.append((numero, valori))
        finally:
            libro.close()
    except ErroreStapply:
        raise
    except Exception as errore:
        # openpyxl solleva errori di ogni tipo (zip, XML, fogli mancanti) su un file rovinato.
        logger.warning("lettura xlsx non riuscita: %r", errore)
        raise ContenutoNonValido(
            "Il file Excel non si riesce a leggere: potrebbe essere danneggiato o protetto."
        ) from errore
    if not righe:
        raise ContenutoNonValido("Il primo foglio è vuoto: manca la riga di intestazione.")
    return righe[0][1], righe[1:]


def _valore_xlsx(valore: object) -> object:
    if isinstance(valore, bool):  # prima di int, di cui bool è una sottoclasse
        return int(valore)
    if valore is None or isinstance(valore, int | float):
        return valore
    if isinstance(valore, datetime):  # prima di date, per lo stesso motivo
        if valore.time() == orario():
            return valore.date().isoformat()
        return valore.isoformat(sep=" ", timespec="seconds")
    if isinstance(valore, date | orario):
        return valore.isoformat()
    if isinstance(valore, timedelta):
        return str(valore)
    return str(valore).strip() or None


def _prepara(intestazione: Riga, righe: list[tuple[int, Riga]]) -> tuple[list[str], list[Riga]]:
    """Righe tutte larghe quanto l'intestazione e nomi di colonna sanificati.

    Le colonne senza intestazione e senza dati (i separatori in più di molti export) spariscono.
    """
    larghezza = len(intestazione)
    if larghezza > MAX_COLONNE:
        raise ContenutoNonValido(
            f"La tabella ha {larghezza} colonne: al massimo se ne possono importare {MAX_COLONNE}."
        )
    _conta_celle(0, larghezza * len(righe))  # prima di allungare le righe corte
    pari: list[Riga] = []
    for numero, riga in righe:
        if len(riga) > larghezza:
            riga = riga[: max(larghezza, _fine(riga))]
        if len(riga) > larghezza:
            raise ContenutoNonValido(
                f"La riga {numero} ha {len(riga)} valori, ma l'intestazione ha {larghezza} colonne."
            )
        pari.append(riga + [None] * (larghezza - len(riga)))
    if not pari:
        raise ContenutoNonValido("La tabella non ha righe di dati sotto l'intestazione.")

    tenute = [
        i
        for i, nome in enumerate(intestazione)
        if nome is not None or any(riga[i] is not None for riga in pari)
    ]
    colonne = ids.nomi_colonne([intestazione[i] for i in tenute])
    return colonne, [[riga[i] for i in tenute] for riga in pari]


def _fine(riga: Riga) -> int:
    """La lunghezza della riga senza le celle vuote in coda."""
    return next((i + 1 for i in range(len(riga) - 1, -1, -1) if riga[i] is not None), 0)


def _converti(valore: object, tipo_colonna: str) -> object:
    if valore is None:
        return None
    if tipo_colonna == "INTEGER":
        return int(str(valore))
    if tipo_colonna == "REAL":
        return float(str(valore))
    return str(valore)


def _quota(nome: str) -> str:
    """Un identificatore SQL tra virgolette doppie (i nomi sono già sanificati: per scrupolo)."""
    return '"' + nome.replace('"', '""') + '"'


def _connetti_sola_lettura(percorso: Path) -> sqlite3.Connection:
    # mode=ro: SQLite stesso rifiuta le scritture, e un file mancante non viene creato.
    return sqlite3.connect(f"{percorso.resolve().as_uri()}?mode=ro", uri=True, isolation_level=None)


def _nomi(con: sqlite3.Connection) -> list[str]:
    return [
        riga[0]
        for riga in con.execute(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' "
            "ESCAPE '\\' ORDER BY name"
        )
    ]


def _nomi_tabelle(percorso: Path) -> list[str]:
    if not percorso.exists():
        return []
    with closing(_connetti_sola_lettura(percorso)) as con:
        return _nomi(con)


def _leggi_meta(agent_id: str) -> dict[str, dict[str, str]]:
    try:
        return json.loads(storage.percorso_meta_tabelle(agent_id).read_text("utf-8"))
    except FileNotFoundError:
        return {}


def _scrivi_meta(agent_id: str, meta: dict[str, dict[str, str]]) -> None:
    percorso = storage.percorso_meta_tabelle(agent_id)
    provvisorio = percorso.with_name(percorso.name + ".tmp")
    provvisorio.write_text(json.dumps(meta, ensure_ascii=False, indent=2, sort_keys=True), "utf-8")
    os.replace(provvisorio, percorso)  # mai un JSON scritto a metà
