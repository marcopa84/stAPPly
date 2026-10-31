import pytest

from app.core import ingest, storage, tables, vectorstore
from app.core.errors import ContenutoNonValido
from tests.conftest import messaggi, scrivi_sessione


def carica_tutto(agent_id: str) -> None:
    ingest.indicizza_documento(agent_id, "faq.md", b"Domande frequenti.")
    tables.importa_tabella(agent_id, "prestiti.csv", b"id,giorni\n1,30\n")
    scrivi_sessione(f"{agent_id}:s1", "Ciao")


def test_percorsi_sotto_data_dir(impostazioni):
    radice = impostazioni.data_dir
    assert storage.cartella_chroma() == radice / "chroma"
    assert storage.percorso_sessioni() == radice / "sessions.db"
    assert storage.cartella_file("demo") == radice / "agents" / "demo" / "files"
    assert storage.percorso_tabelle("demo") == radice / "agents" / "demo" / "tables.db"
    assert storage.percorso_meta_tabelle("demo") == radice / "agents" / "demo" / "tables.json"


@pytest.mark.parametrize("agent_id", ["../fuori", "demo/../../x", "", "Demo"])
def test_un_agent_id_non_valido_non_diventa_un_percorso(agent_id):
    with pytest.raises(ContenutoNonValido):
        storage.cartella_agente(agent_id)
    with pytest.raises(ContenutoNonValido):
        storage.cancella_agente(agent_id)


def test_cancella_agente_toglie_tutto_e_solo_il_suo():
    carica_tutto("demo")
    carica_tutto("demo-altro")  # "demo-altro:" inizia come "demo", ma è un altro agente
    scrivi_sessione("demo:s2", "Un'altra conversazione")

    storage.cancella_agente("demo")

    assert ingest.lista_documenti("demo") == []
    assert tables.lista_tabelle("demo") == []
    assert vectorstore.collection_esistente("demo") is None
    assert not storage.cartella_agente("demo").exists()
    assert messaggi("demo:s1") == []
    assert messaggi("demo:s2") == []

    assert [d["document_id"] for d in ingest.lista_documenti("demo-altro")] == ["faq"]
    assert [t["table"] for t in tables.lista_tabelle("demo-altro")] == ["prestiti"]
    assert messaggi("demo-altro:s1") == [{"role": "user", "content": "Ciao"}]


def test_cancella_agente_e_idempotente():
    carica_tutto("demo")
    storage.cancella_agente("demo")
    storage.cancella_agente("demo")
    storage.cancella_agente("mai-visto")
    assert ingest.lista_documenti("demo") == []


def test_cancella_agente_senza_chat_non_crea_sessions_db():
    ingest.indicizza_documento("demo", "faq.md", b"Domande frequenti.")
    storage.cancella_agente("demo")
    assert not storage.percorso_sessioni().exists()


def test_cancella_sessione():
    scrivi_sessione("demo:s1", "Prima")
    scrivi_sessione("demo:s2", "Seconda")

    storage.cancella_sessione("demo", "s1")
    storage.cancella_sessione("demo", "s1")  # già cancellata: nessun errore

    assert messaggi("demo:s1") == []
    assert messaggi("demo:s2") == [{"role": "user", "content": "Seconda"}]


def test_adesso_nel_formato_del_contratto():
    assert len(storage.adesso()) == len("2026-10-31T10:12:04Z")
    assert storage.adesso().endswith("Z")
