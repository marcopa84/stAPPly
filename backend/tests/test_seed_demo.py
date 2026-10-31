"""scripts/seed_demo.py: gli agenti demo, idempotente, e niente dati toccati senza chiave."""

from app.core import ingest, tables
from scripts import seed_demo
from tests.conftest import SEED


def nomi_dei_file(cartella) -> set[str]:
    return {p.name for p in seed_demo.file_in(cartella)}


def test_senza_chiave_esce_prima_di_toccare_i_dati(imposta, capsys):
    imposta(openai_api_key="")
    ingest.indicizza_documento("demo-valtesa", "mio.md", b"Un documento mio.")

    assert seed_demo.main() == 1

    assert "Manca OPENAI_API_KEY" in capsys.readouterr().err
    assert [d["filename"] for d in ingest.lista_documenti("demo-valtesa")] == ["mio.md"]


def test_prepara_gli_agenti_demo_ed_e_idempotente(capsys):
    # La chiave finta di conftest basta a passare il controllo; gli embedding sono finti.
    ingest.indicizza_documento("demo-valtesa", "vecchio.md", b"Da togliere.")

    assert seed_demo.main() == 0
    assert seed_demo.main() == 0

    for agent_id, tema in (("demo-valtesa", "valtesa"), ("demo-valdoria", "valdoria")):
        documenti = {d["filename"] for d in ingest.lista_documenti(agent_id)}
        assert documenti == nomi_dei_file(SEED / tema / "documenti")
        tabelle = {t["filename"] for t in tables.lista_tabelle(agent_id)}
        assert tabelle == nomi_dei_file(SEED / tema / "tabelle")
    assert capsys.readouterr().out.count("Agenti demo pronti.") == 2
