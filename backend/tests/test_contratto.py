"""Il contratto con Laravel: per ogni esempio in docs/contract/ una risposta vera dell'API deve
avere la stessa forma (chiavi a ogni livello e tipi JSON dei valori).

I test del frontend usano gli stessi esempi come risposte finte: se la forma diverge qui, il
frontend sta provando contro un backend che non esiste.
"""

import re
from collections.abc import Callable, Iterator

import httpx
import pytest
from fastapi.testclient import TestClient

from app.core import agent
from app.main import create_app
from tests.conftest import CONTRATTO, NOTE_SPESE, SEED, contratto, forma
from tests.modello_finto import ModelloFinto, chiama, cita_il_primo_passaggio

CHIAVE = "chiave-di-prova"
CIRCOLARI = SEED / "valtesa" / "documenti" / "circolari-personale-2026.html"
CHAT = {
    "session_id": "01jbx3k6v2c8m0q4w5t7y9z1ab",
    "message": "Qual è il massimale per una cena in trasferta a Milano?",
    "instructions": "Sei l'assistente dell'ufficio del personale di Valtesa S.r.l.",
    "agent_name": "Assistente Valtesa",
}


@pytest.fixture
def client(imposta) -> Iterator[TestClient]:
    imposta(internal_api_key=CHIAVE)
    with TestClient(create_app(), headers={"X-Internal-Key": CHIAVE}) as client:
        yield client


def carica_documento(client: TestClient) -> httpx.Response:
    files = {"file": (CIRCOLARI.name, CIRCOLARI.read_bytes())}
    return client.post("/agents/demo-valtesa/documents", files=files)


def carica_tabella(client: TestClient) -> httpx.Response:
    files = {"file": (NOTE_SPESE.name, NOTE_SPESE.read_bytes())}
    return client.post("/agents/demo-valtesa/tables", files=files)


def usa_modello(monkeypatch: pytest.MonkeyPatch, modello: ModelloFinto) -> None:
    monkeypatch.setattr(agent, "crea_modello", lambda: modello)


def chat(client: TestClient, monkeypatch: pytest.MonkeyPatch, imposta) -> httpx.Response:
    carica_documento(client)
    domanda = {"domanda": "massimale cena trasferta Milano"}
    usa_modello(
        monkeypatch, ModelloFinto([chiama("cerca_documenti", domanda)], cita_il_primo_passaggio)
    )
    return client.post("/agents/demo-valtesa/chat", json=CHAT)


def chat_max_turns(client: TestClient, monkeypatch: pytest.MonkeyPatch, imposta) -> httpx.Response:
    carica_tabella(client)
    imposta(max_turns=1)
    sql = {"sql": "SELECT COUNT(*) FROM note_spese WHERE stato = 'in attesa'"}
    usa_modello(monkeypatch, ModelloFinto([chiama("query_db", sql)]))
    return client.post("/agents/demo-valtesa/chat", json=CHAT)


def sql(client: TestClient, *_: object) -> httpx.Response:
    carica_tabella(client)
    query = (
        "SELECT stato, COUNT(*) AS quante, ROUND(SUM(importo), 2) AS totale "
        "FROM note_spese GROUP BY stato ORDER BY stato"
    )
    return client.post("/agents/demo-valtesa/sql", json={"sql": query})


def lista_documenti(client: TestClient, *_: object) -> httpx.Response:
    carica_documento(client)
    return client.get("/agents/demo-valtesa/documents")


def lista_tabelle(client: TestClient, *_: object) -> httpx.Response:
    carica_tabella(client)
    return client.get("/agents/demo-valtesa/tables")


def clone(client: TestClient, *_: object) -> httpx.Response:
    carica_documento(client)
    carica_tabella(client)
    return client.post("/agents/01jbx3k6v2c8m0q4w5t7y9z1ab/clone", json={"from": "demo-valtesa"})


def errore(client: TestClient, *_: object) -> httpx.Response:
    files = {"file": ("regolamento.pdf", b"Non sono un PDF.")}
    return client.post("/agents/demo-valtesa/documents", files=files)


RISPOSTE: dict[str, Callable[..., httpx.Response]] = {
    "health.200.json": lambda client, *_: client.get("/health"),
    "documents.upload.201.json": lambda client, *_: carica_documento(client),
    "documents.list.200.json": lista_documenti,
    "tables.upload.201.json": lambda client, *_: carica_tabella(client),
    "tables.list.200.json": lista_tabelle,
    "sql.200.json": sql,
    "chat.200.json": chat,
    "chat.max-turns.200.json": chat_max_turns,
    "agents.clone.201.json": clone,
    "error.json": errore,
}


def test_ogni_esempio_del_contratto_ha_un_test():
    assert set(RISPOSTE) == {p.name for p in CONTRATTO.glob("*.json")}


@pytest.mark.parametrize("esempio", sorted(p.name for p in CONTRATTO.glob("*.json")))
def test_la_risposta_ha_la_forma_dell_esempio(esempio, client, monkeypatch, imposta):
    risposta = RISPOSTE[esempio](client, monkeypatch, imposta)

    codice = re.search(r"\.(\d{3})\.json$", esempio)
    if codice:
        assert risposta.status_code == int(codice.group(1)), risposta.text
    else:
        assert risposta.status_code >= 400
    assert forma(risposta.json()) == forma(contratto(esempio))


def test_forma_confronta_chiavi_e_tipi_a_ogni_livello():
    esempio = contratto("chat.200.json")
    # Valori diversi con la stessa struttura hanno la stessa forma...
    assert forma(esempio | {"answer": "altro testo", "route": "dati"}) == forma(esempio)
    # ...una chiave mancante o un tipo diverso, no.
    senza_testo = esempio | {
        "sources": [{k: v for k, v in esempio["sources"][0].items() if k != "text"}]
    }
    assert forma(senza_testo) != forma(esempio)
    numero_al_posto_di_testo = esempio | {"route": 1}
    assert forma(numero_al_posto_di_testo) != forma(esempio)
    argomenti_come_stringa = esempio | {"tools": [{"name": "cerca_documenti", "arguments": "{}"}]}
    assert forma(argomenti_come_stringa) != forma(esempio)
