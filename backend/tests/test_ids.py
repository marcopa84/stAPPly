import re

import pytest

from app.core import ids
from app.core.errors import ContenutoNonValido


@pytest.mark.parametrize(
    "agent_id",
    ["demo-valtesa", "demo-valdoria", "01jbx3k6v2c8m0q4w5t7y9z1ab", "a", "9", "a" * 50],
)
def test_agent_id_validi(agent_id):
    assert ids.agent_id_valido(agent_id)
    assert ids.controlla_agent_id(agent_id) == agent_id


@pytest.mark.parametrize(
    "agent_id",
    [
        "",
        "-demo",
        "demo-",
        "Demo",
        "demo_valtesa",
        "demo valtesa",
        "../demo",
        "démo",
        "a" * 51,
        "demo\n",
    ],
)
def test_agent_id_non_validi(agent_id):
    assert not ids.agent_id_valido(agent_id)
    with pytest.raises(ContenutoNonValido):
        ids.controlla_agent_id(agent_id)


@pytest.mark.parametrize("session_id", ["01jbx3k6v2c8m0q4w5t7y9z1ab", "Prova_1-b", "x" * 64])
def test_session_id_validi(session_id):
    assert ids.session_id_valido(session_id)


@pytest.mark.parametrize("session_id", ["", "x" * 65, "con spazio", "a:b", "s\n", "è"])
def test_session_id_non_validi(session_id):
    assert not ids.session_id_valido(session_id)
    with pytest.raises(ContenutoNonValido):
        ids.controlla_session_id(session_id)


def test_chiave_sessione_e_collection():
    assert ids.chiave_sessione("demo-valtesa", "01jbx3") == "demo-valtesa:01jbx3"
    assert ids.nome_collection("demo-valtesa") == "agent_demo-valtesa"
    with pytest.raises(ContenutoNonValido):
        ids.chiave_sessione("demo-valtesa", "a:b")


@pytest.mark.parametrize(
    ("filename", "atteso"),
    [
        ("Regolamento Ferie (2026).pdf", "regolamento-ferie-2026"),  # l'esempio di docs/api.md
        ("regolamento-ferie-permessi.pdf", "regolamento-ferie-permessi"),
        ("Carità e Perché.docx", "carita-e-perche"),
        ("  __Note__ spese__.md", "note-spese"),
        ("../../etc/Passwd.txt", "passwd"),
        ("C:\\Users\\marco\\Circolare 7.html", "circolare-7"),
        ("!!!.md", "documento"),
        ("a" * 100 + ".txt", "a" * 80),
        ("a" * 79 + " b.txt", "a" * 79),  # il taglio a 80 non lascia un trattino in coda
    ],
)
def test_document_id(filename, atteso):
    assert ids.document_id(filename) == atteso
    assert ids.document_id_valido(atteso)


@pytest.mark.parametrize("valore", ["", "-faq", "faq-", "faq--2", "FAQ", "../faq", "a" * 81])
def test_document_id_non_validi(valore):
    assert not ids.document_id_valido(valore)


def test_chunk_id():
    assert ids.chunk_id("faq", 0) == "faq-000"
    assert ids.chunk_id("faq", 42) == "faq-042"
    assert ids.chunk_id("faq", 1234) == "faq-1234"


@pytest.mark.parametrize(
    ("filename", "atteso"),
    [
        ("Note spese.csv", "note_spese"),  # l'esempio di docs/api.md
        ("note_spese.csv", "note_spese"),
        ("Vendite-2026.xlsx", "vendite_2026"),
        ("2026 vendite.xlsx", "t_2026_vendite"),
        ("Città.csv", "citta"),  # l'altro esempio di docs/api.md
        ("Perché Sì.xlsx", "perche_si"),
        ("%%%.csv", "tabella"),
        ("sqlite_master.csv", "t_sqlite_master"),
        ("SQLite_Stat1.csv", "t_sqlite_stat1"),
        ("sqlitè_x.csv", "t_sqlite_x"),  # il prefisso si decide dopo aver tolto gli accenti
        ("sqlite.csv", "sqlite"),
        ("x" * 70 + ".csv", "x" * 60),
    ],
)
def test_nome_tabella(filename, atteso):
    assert ids.nome_tabella(filename) == atteso
    assert re.fullmatch(ids.TABLE_PATTERN, atteso)  # l'API valida il nome con questa regex


def test_nomi_colonne():
    intestazione = ["id", "Dipendente ID", "Importo (€)", "", None, "id", "ID", "2026", "€"]
    assert ids.nomi_colonne(intestazione) == [
        "id",
        "dipendente_id",
        "importo_",
        "colonna_4",
        "colonna_5",
        "id_2",
        "id_3",
        "t_2026",
        "colonna_9",
    ]


def test_nomi_colonne_senza_accenti_e_con_la_regola_di_sqlite():
    intestazione = ["Quantità", "Città", "Città ", "Unità di misura", "sqlite_rowid"]
    assert ids.nomi_colonne(intestazione) == [
        "quantita",  # l'esempio di docs/api.md
        "citta",
        "citta_2",
        "unita_di_misura",
        "t_sqlite_rowid",
    ]


def test_nomi_colonne_doppioni_restano_entro_60_caratteri():
    nomi = ids.nomi_colonne(["x" * 70, "x" * 70, "x" * 70])
    assert nomi == ["x" * 60, "x" * 58 + "_2", "x" * 58 + "_3"]


def test_nomi_colonne_doppione_di_un_nome_generato():
    assert ids.nomi_colonne(["colonna_2", "", "a", "a_2", "a"]) == [
        "colonna_2",
        "colonna_2_2",
        "a",
        "a_2",
        "a_3",
    ]


@pytest.mark.parametrize(
    ("filename", "atteso"),
    [
        ("faq.pdf", "faq.pdf"),
        ("../../app/main.py", "main.py"),
        ("C:\\fakepath\\Regolamento Ferie.pdf", "Regolamento Ferie.pdf"),
        ("  doc\x00\x1f.md  ", "doc.md"),
    ],
)
def test_nome_file(filename, atteso):
    assert ids.nome_file(filename) == atteso


@pytest.mark.parametrize("filename", ["", "   ", "cartella/", "..", "a/.."])
def test_nome_file_non_valido(filename):
    with pytest.raises(ContenutoNonValido):
        ids.nome_file(filename)
