import csv
import io
import sqlite3
from collections import defaultdict
from datetime import date, datetime, time

import pytest
from openpyxl import Workbook

from app.core import storage, tables
from app.core.errors import ContenutoNonValido, LimiteRaggiunto, NonTrovato
from tests.conftest import SEED, contratto, forma, zip_con

AGENTE = "demo-test"
NOTE_SPESE = SEED / "valtesa" / "tabelle" / "note_spese.csv"


def importa_note_spese(agent_id: str = AGENTE) -> dict:
    return tables.importa_tabella(agent_id, NOTE_SPESE.name, NOTE_SPESE.read_bytes())


def xlsx(*fogli: list[list[object]]) -> bytes:
    """Un .xlsx in memoria, un foglio per lista di righe; attivo l'ultimo, come capita a mano."""
    libro = Workbook()
    libro.remove(libro.active)
    for numero, righe in enumerate(fogli, 1):
        foglio = libro.create_sheet(f"Foglio{numero}")
        for riga in righe:
            foglio.append(riga)
    libro.active = len(fogli) - 1
    buffer = io.BytesIO()
    libro.save(buffer)
    return buffer.getvalue()


def righe_db(sql: str, agent_id: str = AGENTE) -> list[tuple]:
    with sqlite3.connect(storage.percorso_tabelle(agent_id)) as con:
        return con.execute(sql).fetchall()


@pytest.mark.parametrize(
    ("valori", "atteso"),
    [
        (["1", "2", None, "-3"], "INTEGER"),
        (["1", "2.5", "1e3"], "REAL"),
        ([3, 4.5], "REAL"),  # i valori numerici di un .xlsx
        (["1", "due"], "TEXT"),
        (["007", "008"], "TEXT"),  # codici con zeri iniziali: non si perdono
        (["1_000"], "TEXT"),
        (["nan", "inf"], "TEXT"),
        (["12345678901234567890"], "REAL"),  # oltre i 64 bit non è un INTEGER di SQLite
        ([None, None], "TEXT"),
    ],
)
def test_tipo(valori, atteso):
    assert tables.tipo(valori) == atteso


def test_importa_il_csv_del_seed_come_nel_contratto():
    risposta = importa_note_spese()

    esempio = contratto("tables.upload.201.json")
    assert forma(risposta) == forma(esempio)
    assert risposta["table"] == esempio["table"] == "note_spese"
    assert risposta["filename"] == "note_spese.csv"
    assert risposta["columns"] == esempio["columns"]
    with NOTE_SPESE.open(encoding="utf-8") as file:
        assert risposta["rows"] == len(list(csv.DictReader(file)))
    assert risposta["replaced"] is False


def test_una_select_coincide_con_il_conto_fatto_a_mano():
    importa_note_spese()
    totali: dict[str, float] = defaultdict(float)
    with NOTE_SPESE.open(encoding="utf-8") as file:
        for riga in csv.DictReader(file):
            totali[riga["stato"]] += float(riga["importo"])

    risultato = tables.esegui_select(
        AGENTE,
        "SELECT stato, COUNT(*) AS quante, ROUND(SUM(importo), 2) AS totale "
        "FROM note_spese GROUP BY stato ORDER BY stato",
        50,
    )

    assert forma(risultato) == forma(contratto("sql.200.json"))
    assert risultato["columns"] == ["stato", "quante", "totale"]
    assert {r[0]: r[2] for r in risultato["rows"]} == pytest.approx(
        {stato: round(t, 2) for stato, t in totali.items()}
    )


def test_csv_con_punto_e_virgola_bom_celle_vuote_e_intestazioni_da_sanificare():
    contenuto = (
        "\ufeff\n"  # una riga vuota prima dell'intestazione non inganna sul separatore
        "ID;Nome e cognome;Importo (€);;id;\n"
        '1;"Rossi; Mario";12.5;;x;\n'
        "\n"
        "2;Bianchi Anna;;;y;\n"
        "  3 ; Verdi ;7;;;\n"
    ).encode()

    risposta = tables.importa_tabella(AGENTE, "Spese Marzo.csv", contenuto)

    assert risposta["table"] == "spese_marzo"
    assert risposta["rows"] == 3
    # La colonna senza intestazione e senza dati sparisce; i doppioni prendono _2.
    assert risposta["columns"] == [
        {"name": "id", "type": "INTEGER"},
        {"name": "nome_e_cognome", "type": "TEXT"},
        {"name": "importo_", "type": "REAL"},
        {"name": "id_2", "type": "TEXT"},
    ]
    assert righe_db("SELECT * FROM spese_marzo ORDER BY id") == [
        (1, "Rossi; Mario", 12.5, "x"),
        (2, "Bianchi Anna", None, "y"),
        (3, "Verdi", 7.0, None),
    ]


def test_csv_di_excel_in_italiano_con_i_numeri_all_italiana():
    # Come lo salva Excel in italiano: BOM, punto e virgola, fine riga \r\n, virgola decimale
    # e punto delle migliaia; codici con zeri iniziali e date con i punti restano testo.
    contenuto = (
        "\ufeffCodice;Prodotto;Quantità;Prezzo unitario;Totale;Sconto;Consegna;Città;Note\r\n"
        "007;Penne;12;4,50;54,00;-12,3;15.07.2026;Forlì;\r\n"
        '010;Carta A4;1.200;0,99;1.188,00;;16.07.2026;Cesena;"consegna; urgente"\r\n'
        "120;Toner;3;1.234,50;3.703,50;0;17.07.2026;Rimini;4,50 o meno\r\n"
    ).encode()

    risposta = tables.importa_tabella(AGENTE, "Ordini.csv", contenuto)

    assert [(c["name"], c["type"]) for c in risposta["columns"]] == [
        ("codice", "TEXT"),
        ("prodotto", "TEXT"),
        ("quantita", "INTEGER"),
        ("prezzo_unitario", "REAL"),
        ("totale", "REAL"),
        ("sconto", "REAL"),
        ("consegna", "TEXT"),
        ("citta", "TEXT"),
        ("note", "TEXT"),
    ]
    assert righe_db("SELECT * FROM ordini ORDER BY codice") == [
        ("007", "Penne", 12, 4.5, 54.0, -12.3, "15.07.2026", "Forlì", None),
        ("010", "Carta A4", 1200, 0.99, 1188.0, None, "16.07.2026", "Cesena", "consegna; urgente"),
        ("120", "Toner", 3, 1234.5, 3703.5, 0.0, "17.07.2026", "Rimini", "4,50 o meno"),
    ]
    # Il conto in SQL coincide con quello fatto a mano sui numeri del file.
    somme = tables.esegui_select(AGENTE, "SELECT SUM(quantita), SUM(totale) FROM ordini", 1)
    assert somme["rows"] == [[12 + 1200 + 3, 54.0 + 1188.0 + 3703.5]]


@pytest.mark.parametrize(
    ("valori", "tipo_colonna", "salvati"),
    [
        (["1.234", "5"], "INTEGER", [1234, 5]),  # col punto e virgola il punto è delle migliaia
        (["1.234.567,8", "+2"], "REAL", [1234567.8, 2.0]),
        (["1,5E+03", "-0,25"], "REAL", [1500.0, -0.25]),
        (["12.5", "7"], "REAL", [12.5, 7.0]),  # non all'italiana: si legge come sempre
        (["1.234", "12.5"], "REAL", [1.234, 12.5]),  # tutta la colonna in un modo solo
        (["4,50", "12.5"], "TEXT", ["4,50", "12.5"]),  # due formati: non è una colonna di numeri
        (["4,50", "n/d"], "TEXT", ["4,50", "n/d"]),  # testo: i valori restano quelli del file
        (["0,5", "007"], "TEXT", ["0,5", "007"]),
        (["1.23", "4,5"], "TEXT", ["1.23", "4,5"]),  # "1.23" non ha gruppi da tre cifre
    ],
)
def test_numeri_all_italiana_solo_se_lo_e_tutta_la_colonna(valori, tipo_colonna, salvati):
    contenuto = ("id;x\n" + "".join(f"{i};{v}\n" for i, v in enumerate(valori))).encode()
    risposta = tables.importa_tabella(AGENTE, "numeri.csv", contenuto)
    assert risposta["columns"][1] == {"name": "x", "type": tipo_colonna}
    assert [r[0] for r in righe_db("SELECT x FROM numeri ORDER BY id")] == salvati


def test_con_la_virgola_come_separatore_i_numeri_all_italiana_restano_testo():
    contenuto = b'id,importo,peso\n1,"4,50",1.234\n2,"1.234,50",2\n'
    risposta = tables.importa_tabella(AGENTE, "spese.csv", contenuto)
    assert [c["type"] for c in risposta["columns"]] == ["INTEGER", "TEXT", "REAL"]
    assert righe_db("SELECT importo, peso FROM spese ORDER BY id") == [
        ("4,50", 1.234),
        ("1.234,50", 2.0),
    ]


def test_xlsx_primo_foglio_con_date_e_colonne_vuote_in_coda():
    contenuto = xlsx(
        [
            ["Data", "Ora", "Arrivo", "Importo", "Pagato", None, None],
            [date(2026, 7, 15), time(9, 30), datetime(2026, 7, 15, 9, 30), 12, True, None],
            [datetime(2026, 7, 16), time(18, 0), datetime(2026, 7, 16, 18, 5, 7), 7.5, False],
            [None, None, None, None, None, None, None],
        ],
        [["altro", "foglio"], [1, 2]],
    )

    risposta = tables.importa_tabella(AGENTE, "presenze.xlsx", contenuto)

    assert [(c["name"], c["type"]) for c in risposta["columns"]] == [
        ("data", "TEXT"),
        ("ora", "TEXT"),
        ("arrivo", "TEXT"),
        ("importo", "REAL"),
        ("pagato", "INTEGER"),
    ]
    assert righe_db("SELECT * FROM presenze") == [
        ("2026-07-15", "09:30:00", "2026-07-15 09:30:00", 12.0, 1),
        ("2026-07-16", "18:00:00", "2026-07-16 18:05:07", 7.5, 0),
    ]
    assert tables.esegui_select(
        AGENTE, "SELECT COUNT(*) FROM presenze WHERE data >= date('2026-07-16')", 10
    )["rows"] == [[1]]


@pytest.mark.parametrize(
    ("filename", "contenuto", "messaggio"),
    [
        ("vuota.csv", b"", "intestazione"),
        ("vuota.csv", b"\n\n", "intestazione"),
        ("solo_intestazione.csv", b"id,nome\n", "righe di dati"),
        ("larga.csv", b"id,nome\n1,a\n2,b,c\n", "riga 3 ha 3 valori"),
        ("vuota.xlsx", xlsx([]), "intestazione"),
        ("solo_intestazione.xlsx", xlsx([["id", "nome"]]), "righe di dati"),
        ("rotto.xlsx", zip_con(**{"xl/workbook.xml": b"<non-xml"}), "Excel"),
    ],
)
def test_tabelle_vuote_o_malformate(filename, contenuto, messaggio):
    with pytest.raises(ContenutoNonValido, match=messaggio):
        tables.importa_tabella(AGENTE, filename, contenuto)
    assert tables.lista_tabelle(AGENTE) == []


def test_sostituzione_e_limite_tabelle(imposta):
    imposta(max_tables_per_agent=2)
    tables.importa_tabella(AGENTE, "a.csv", b"x\n1\n")
    tables.importa_tabella(AGENTE, "b.csv", b"x\n1\n")

    with pytest.raises(LimiteRaggiunto, match="2 tabelle"):
        tables.importa_tabella(AGENTE, "c.csv", b"x\n1\n")
    risposta = tables.importa_tabella(AGENTE, "A.xlsx", xlsx([["y"], ["testo"], ["altro"]]))

    assert risposta["replaced"] is True
    assert righe_db("SELECT * FROM a") == [("testo",), ("altro",)]
    assert [(t["table"], t["filename"]) for t in tables.lista_tabelle(AGENTE)] == [
        ("a", "A.xlsx"),
        ("b", "b.csv"),
    ]


def test_una_sostituzione_malformata_lascia_la_tabella_di_prima():
    importa_note_spese()
    with pytest.raises(ContenutoNonValido):
        tables.importa_tabella(AGENTE, "note_spese.csv", b"id\n1,2,3\n")
    assert righe_db("SELECT COUNT(*) FROM note_spese") == [(401,)]


def test_salva_e_atomica():
    percorso = storage.percorso_tabelle(AGENTE)
    percorso.parent.mkdir(parents=True)
    tables.salva(percorso, "t", ["a"], ["INTEGER"], [[1], [2]])

    # La seconda riga ha un valore di troppo: l'INSERT fallisce dopo DROP e CREATE.
    with pytest.raises(ContenutoNonValido, match="non si può salvare"):
        tables.salva(percorso, "t", ["b"], ["TEXT"], [["x"], ["y", "z"]])
    assert righe_db("SELECT a FROM t") == [(1,), (2,)]


def test_lista_tabelle_con_le_chiavi_del_contratto_e_metadati_fuori_dal_db():
    importa_note_spese()
    tables.importa_tabella(AGENTE, "Assenze.csv", b"id,tipo\n1,ferie\n")

    elenco = tables.lista_tabelle(AGENTE)

    assert forma(elenco) == forma(contratto("tables.list.200.json")["tables"])
    assert [t["table"] for t in elenco] == ["assenze", "note_spese"]
    assert elenco[0]["filename"] == "Assenze.csv"
    assert elenco[0]["rows"] == 1
    # Filename e data stanno in tables.json: query_db non li vede.
    assert righe_db("SELECT name FROM sqlite_master ORDER BY name") == [
        ("assenze",),
        ("note_spese",),
    ]
    assert storage.percorso_meta_tabelle(AGENTE).exists()


def test_agente_mai_visto():
    assert tables.lista_tabelle("mai-visto") == []
    assert tables.schema_testo("mai-visto") == ""
    assert tables.ha_tabelle("mai-visto") is False
    with pytest.raises(NonTrovato):
        tables.esegui_select("mai-visto", "SELECT 1", 10)
    assert not storage.cartella_agente("mai-visto").exists()


def test_cancella_tabella():
    importa_note_spese()
    tables.importa_tabella(AGENTE, "assenze.csv", b"id\n1\n")

    tables.cancella_tabella(AGENTE, "assenze")

    assert [t["table"] for t in tables.lista_tabelle(AGENTE)] == ["note_spese"]
    with pytest.raises(NonTrovato):
        tables.cancella_tabella(AGENTE, "assenze")


@pytest.mark.parametrize("nome", ["mai_caricata", 'note_spese"; DROP TABLE note_spese; --', ""])
def test_cancella_tabella_inesistente(nome):
    importa_note_spese()
    with pytest.raises(NonTrovato):
        tables.cancella_tabella(AGENTE, nome)
    assert tables.ha_tabelle(AGENTE)


def test_dopo_l_ultima_tabella_l_agente_non_ha_tabelle():
    tables.importa_tabella(AGENTE, "assenze.csv", b"id\n1\n")
    tables.cancella_tabella(AGENTE, "assenze")
    assert tables.ha_tabelle(AGENTE) is False
    with pytest.raises(NonTrovato):
        tables.esegui_select(AGENTE, "SELECT 1", 10)


def test_schema_testo():
    importa_note_spese()
    tables.importa_tabella(AGENTE, "assenze.csv", b"id,giorni\n1,2.5\n")
    assert tables.schema_testo(AGENTE) == (
        "assenze(id INTEGER, giorni REAL)\n"
        "note_spese(id INTEGER, dipendente_id INTEGER, data TEXT, citta TEXT, voce TEXT, "
        "importo REAL, stato TEXT)"
    )


@pytest.mark.parametrize(
    "sql",
    [
        "SELECT id, importo FROM note_spese WHERE stato = 'approvata' ORDER BY id LIMIT 3",
        "select id, importo from note_spese where stato = 'approvata' order by id limit 3;",
        "-- le prime tre\n/* approvate */ SELECT id, importo FROM note_spese "
        "WHERE stato = 'approvata' ORDER BY id LIMIT 3",
        "WITH ok AS (SELECT * FROM note_spese WHERE stato = 'approvata') "
        "SELECT id, importo FROM ok ORDER BY id LIMIT 3",
    ],
)
def test_esegui_select(sql):
    importa_note_spese()
    risultato = tables.esegui_select(AGENTE, sql, 50)
    assert risultato == {
        "columns": ["id", "importo"],
        "rows": [[1001, 80.0], [1002, 90.8], [1003, 15.2]],
        "truncated": False,
    }


def test_esegui_select_tronca_al_limite():
    importa_note_spese()
    risultato = tables.esegui_select(AGENTE, "SELECT id FROM note_spese ORDER BY id", 10)
    assert len(risultato["rows"]) == 10
    assert risultato["truncated"] is True
    tutte = tables.esegui_select(AGENTE, "SELECT id FROM note_spese", 401)
    assert len(tutte["rows"]) == 401
    assert tutte["truncated"] is False


@pytest.mark.parametrize(
    "sql",
    [
        "DELETE FROM note_spese",
        "UPDATE note_spese SET importo = 0",
        "DROP TABLE note_spese",
        "INSERT INTO note_spese (id) VALUES (1)",
        "ATTACH DATABASE ':memory:' AS altro",
        "PRAGMA writable_schema = ON",
        "VACUUM",
        "",
    ],
)
def test_esegui_select_rifiuta_cio_che_non_e_una_lettura(sql):
    importa_note_spese()
    with pytest.raises(ContenutoNonValido, match="solo query di lettura"):
        tables.esegui_select(AGENTE, sql, 50)
    assert righe_db("SELECT COUNT(*), SUM(importo) > 0 FROM note_spese") == [(401, 1)]


@pytest.mark.parametrize(
    ("sql", "messaggio"),
    [
        # SQL valido che inizia con WITH ma scrive: lo fermano authorizer e sola lettura.
        ("WITH x AS (SELECT 1) DELETE FROM note_spese", "solo letture"),
        ("WITH x AS (SELECT 1) UPDATE note_spese SET importo = 0", "solo letture"),
        ("WITH x AS (SELECT 1) INSERT INTO note_spese (id) SELECT 1 FROM x", "solo letture"),
        ("SELECT 1; DROP TABLE note_spese", "one statement"),
        ("SELECT 1; DELETE FROM note_spese", "one statement"),
        ("SELECT * FROM note_spese WHERE id = ?", "bindings"),
    ],
)
def test_esegui_select_ferma_le_scritture_travestite(sql, messaggio):
    importa_note_spese()
    with pytest.raises(ContenutoNonValido, match=messaggio):
        tables.esegui_select(AGENTE, sql, 50)
    assert righe_db("SELECT COUNT(*), SUM(importo) > 0 FROM note_spese") == [(401, 1)]


def test_esegui_select_riporta_il_messaggio_di_sqlite():
    importa_note_spese()
    with pytest.raises(ContenutoNonValido, match="no such column: totale"):
        tables.esegui_select(AGENTE, "SELECT totale FROM note_spese", 50)


def test_esegui_select_interrompe_le_query_troppo_lunghe():
    importa_note_spese()
    infinita = (
        "WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n) SELECT MAX(x) FROM n"
    )
    with pytest.raises(ContenutoNonValido, match="interrotta"):
        tables.esegui_select(AGENTE, infinita, 50, tempo_massimo=0.05)


def test_esegui_select_restituisce_valori_da_json():
    importa_note_spese()
    risultato = tables.esegui_select(AGENTE, "SELECT x'00ff', 1e999, NULL", 1)
    assert risultato["rows"] == [["00ff", "inf", None]]
