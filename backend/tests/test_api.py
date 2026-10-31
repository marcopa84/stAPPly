"""L'API con TestClient: chiave interna, validazione degli id, upload, liste, cancellazioni,
console SQL, chat ed errori. Il contratto delle risposte è in test_contratto.py."""

import io
import logging
from collections.abc import Iterator
from contextlib import contextmanager

import httpx
import openai
import pytest
from fastapi import UploadFile
from fastapi.testclient import TestClient

from app.api.dipendenze import PEZZO_UPLOAD, leggi_upload
from app.api.errori import MESSAGGI_HTTP, MESSAGGIO_ERRORE_INTERNO
from app.core import agent, ingest
from app.core.errors import FileTroppoGrande, ModelloNonDisponibile
from app.main import create_app
from tests.conftest import NOTE_SPESE, contratto
from tests.modello_finto import ModelloFinto, scrive

CHIAVE = "chiave-di-prova"
CHAT = {
    "session_id": "s1",
    "message": "Ciao",
    "instructions": "Sei l'assistente di Valtesa.",
    "agent_name": "Assistente Valtesa",
}


@pytest.fixture
def client(imposta) -> Iterator[TestClient]:
    imposta(internal_api_key=CHIAVE)
    with TestClient(create_app(), headers={"X-Internal-Key": CHIAVE}) as client:
        yield client


@pytest.fixture
def modello(monkeypatch) -> ModelloFinto:
    """Il modello finto al posto di quello di OpenAI; i passi li aggiunge il test."""
    finto = ModelloFinto()
    monkeypatch.setattr(agent, "crea_modello", lambda: finto)
    return finto


def carica(client: TestClient, agent_id: str, nome: str, contenuto: bytes, tipo: str = "documents"):
    return client.post(f"/agents/{agent_id}/{tipo}", files={"file": (nome, contenuto)})


# --- chiave interna ---------------------------------------------------------------------------


def test_health_senza_chiave(imposta):
    imposta(internal_api_key=CHIAVE)
    risposta = TestClient(create_app()).get("/health")
    assert (risposta.status_code, risposta.json()) == (200, {"status": "ok"})


@pytest.mark.parametrize(
    "intestazioni",
    [
        {},
        {"X-Internal-Key": "sbagliata"},
        {"X-Internal-Key": ""},
        {"X-Internal-Key": "chiavè".encode()},
    ],
    ids=["mancante", "sbagliata", "vuota", "non-ascii"],
)
def test_401_senza_la_chiave_giusta(imposta, intestazioni):
    imposta(internal_api_key=CHIAVE)
    risposta = TestClient(create_app()).get("/agents/demo/documents", headers=intestazioni)
    assert risposta.status_code == 401
    assert risposta.json() == {"detail": "Chiave interna mancante o non valida."}


def test_schema_openapi(client):
    schema = client.get("/openapi.json")
    assert schema.status_code == 200
    percorsi = schema.json()["paths"]
    assert "/agents/{agent_id}/chat" in percorsi
    assert "/agents/{agent_id}/tables/{table}" in percorsi


def test_rotta_inesistente_404_in_italiano(imposta):
    imposta(internal_api_key=CHIAVE)
    # Senza chiave: il routing risponde prima che la chiave venga controllata.
    risposta = TestClient(create_app()).get("/non-esiste")
    assert risposta.status_code == 404
    assert risposta.json() == {"detail": MESSAGGI_HTTP[404]}


@pytest.mark.parametrize(
    ("metodo", "percorso", "ammessi"),
    [("PUT", "/health", "GET"), ("GET", "/agents/demo/clone", "POST")],
)
def test_metodo_non_ammesso_405_in_italiano(client, metodo, percorso, ammessi):
    risposta = client.request(metodo, percorso)
    assert risposta.status_code == 405
    assert risposta.json() == {"detail": MESSAGGI_HTTP[405]}
    assert risposta.headers["allow"] == ammessi  # l'header dello standard resta


def test_internal_api_key_vuota_rifiuta_tutto(imposta):
    imposta(internal_api_key="")
    anonimo = TestClient(create_app())
    assert anonimo.get("/agents/demo/documents", headers={"X-Internal-Key": ""}).status_code == 401
    assert anonimo.get("/agents/demo/documents").status_code == 401
    assert anonimo.get("/health").status_code == 200


# --- validazione ------------------------------------------------------------------------------


@pytest.mark.parametrize(
    ("metodo", "percorso"),
    [
        ("GET", "/agents/Demo/documents"),
        ("GET", "/agents/demo-/tables"),
        ("DELETE", "/agents/demo_1"),
        ("DELETE", "/agents/demo/documents/Faq"),
        ("DELETE", "/agents/demo/documents/faq--2"),
        ("DELETE", "/agents/demo/documents/" + "a" * 81),
        ("DELETE", "/agents/demo/tables/2026_vendite"),
        ("DELETE", "/agents/demo/tables/Note-spese"),
        ("DELETE", "/agents/demo/sessions/a:b"),
        ("DELETE", "/agents/demo/sessions/" + "s" * 65),
    ],
)
def test_id_non_validi_422(client, metodo, percorso):
    assert client.request(metodo, percorso).status_code == 422


@pytest.mark.parametrize(
    "modifica",
    [
        {"session_id": "a b"},
        {"message": ""},
        {"message": "x" * 4001},
        {"instructions": "x" * 8001},
        {"agent_name": ""},
        {"agent_name": "x" * 101},
    ],
)
def test_corpo_della_chat_non_valido_422(client, modello, modifica):
    risposta = client.post("/agents/demo/chat", json=CHAT | modifica)
    assert risposta.status_code == 422
    assert isinstance(risposta.json()["detail"], list)  # il formato di FastAPI
    assert modello.chiamate == []


# --- documenti --------------------------------------------------------------------------------


def test_documenti_upload_lista_sostituzione_e_cancellazione(client):
    testo = b"Il prestito dura 30 giorni."
    caricato = carica(client, "demo", "FAQ Prestiti.md", testo)
    assert caricato.status_code == 201
    assert caricato.json() | {"uploaded_at": "-"} == {
        "document_id": "faq-prestiti",
        "filename": "FAQ Prestiti.md",
        "chunks": 1,
        "size_bytes": len(testo),
        "uploaded_at": "-",
        "replaced": False,
    }
    assert carica(client, "demo", "FAQ Prestiti.md", testo).json()["replaced"] is True

    elenco = client.get("/agents/demo/documents").json()["documents"]
    assert [d["document_id"] for d in elenco] == ["faq-prestiti"]

    assert client.delete("/agents/demo/documents/faq-prestiti").status_code == 204
    assert client.get("/agents/demo/documents").json() == {"documents": []}
    mancante = client.delete("/agents/demo/documents/faq-prestiti")
    assert mancante.status_code == 404
    assert mancante.json() == {"detail": "Il documento «faq-prestiti» non esiste."}


def test_agente_mai_visto_ha_liste_vuote(client):
    assert client.get("/agents/nuovo/documents").json() == {"documents": []}
    assert client.get("/agents/nuovo/tables").json() == {"tables": []}


def test_409_oltre_il_limite_di_documenti(client, imposta):
    imposta(max_documents_per_agent=1)
    assert carica(client, "demo", "uno.md", b"Primo documento.").status_code == 201
    risposta = carica(client, "demo", "due.md", b"Secondo documento.")
    assert risposta.status_code == 409
    assert "Limite di 1 documenti" in risposta.json()["detail"]
    assert carica(client, "demo", "uno.md", b"Sostituzione.").status_code == 201


def test_413_oltre_max_upload_mb(client, imposta):
    imposta(max_upload_mb=1)
    risposta = carica(client, "demo", "grande.txt", b"a" * (3 * 1024 * 1024))
    assert risposta.status_code == 413
    assert risposta.json() == {"detail": "Il file supera il limite di 1 MB."}
    tabella = carica(client, "demo", "grande.csv", b"a\n" * (1024 * 1024), tipo="tables")
    assert tabella.status_code == 413


def test_upload_letto_a_pezzi_si_ferma_oltre_il_limite(imposta):
    imposta(max_upload_mb=1)
    sorgente = io.BytesIO(b"a" * (5 * 1024 * 1024))
    with pytest.raises(FileTroppoGrande):
        leggi_upload(UploadFile(sorgente, filename="grande.txt"))
    assert sorgente.tell() <= 1024 * 1024 + PEZZO_UPLOAD  # il resto non è stato letto


def test_415_estensione_o_firma_sbagliata(client):
    assert carica(client, "demo", "virus.exe", b"MZ").status_code == 415
    finto = carica(client, "demo", "regolamento.pdf", b"Non sono un PDF.")
    assert finto.status_code == 415
    assert finto.json() == contratto("error.json")
    assert carica(client, "demo", "dati.xlsx", b"id,n\n1,2\n", tipo="tables").status_code == 415


def test_422_file_senza_testo(client):
    risposta = carica(client, "demo", "vuoto.md", b"   \n\n  ")
    assert risposta.status_code == 422
    assert isinstance(risposta.json()["detail"], str)


def test_upload_senza_file_422(client):
    assert client.post("/agents/demo/documents").status_code == 422


# --- tabelle e console SQL --------------------------------------------------------------------


def test_tabelle_upload_lista_e_cancellazione(client):
    caricata = carica(client, "demo", NOTE_SPESE.name, NOTE_SPESE.read_bytes(), tipo="tables")
    assert caricata.status_code == 201
    assert (caricata.json()["table"], caricata.json()["rows"]) == ("note_spese", 401)

    elenco = client.get("/agents/demo/tables").json()["tables"]
    assert [t["table"] for t in elenco] == ["note_spese"]

    assert client.delete("/agents/demo/tables/note_spese").status_code == 204
    assert client.delete("/agents/demo/tables/note_spese").status_code == 404
    assert client.get("/agents/demo/tables").json() == {"tables": []}


def test_sql(client, imposta):
    imposta(sql_console_limit=2)
    carica(client, "demo", NOTE_SPESE.name, NOTE_SPESE.read_bytes(), tipo="tables")

    risposta = client.post(
        "/agents/demo/sql",
        json={"sql": "SELECT stato, COUNT(*) AS quante FROM note_spese GROUP BY stato ORDER BY 1"},
    )
    assert risposta.status_code == 200
    assert risposta.json() == {
        "columns": ["stato", "quante"],
        "rows": [["approvata", 323], ["in attesa", 56]],
        "truncated": True,  # le righe sono 3, il limite della console qui è 2
    }


@pytest.mark.parametrize("sql", ["DELETE FROM note_spese", "SELECT * FROM non_esiste"])
def test_sql_rifiutata_o_in_errore_422(client, sql):
    carica(client, "demo", NOTE_SPESE.name, NOTE_SPESE.read_bytes(), tipo="tables")
    risposta = client.post("/agents/demo/sql", json={"sql": sql})
    assert risposta.status_code == 422
    assert isinstance(risposta.json()["detail"], str)


def test_sql_vuota_422(client):
    assert client.post("/agents/demo/sql", json={"sql": ""}).status_code == 422


def test_sql_senza_tabelle_404(client):
    risposta = client.post("/agents/demo/sql", json={"sql": "SELECT 1"})
    assert risposta.status_code == 404
    assert "non ha tabelle" in risposta.json()["detail"]


# --- chat e cancellazioni ---------------------------------------------------------------------


def test_chat(client, modello):
    modello.passi.append(scrive("Ciao, come posso aiutarti?"))
    risposta = client.post("/agents/demo/chat", json=CHAT)
    assert risposta.status_code == 200
    assert risposta.json() == {
        "answer": "Ciao, come posso aiutarti?",
        "status": "ok",
        "route": "nessuna",
        "tools": [],
        "sources": [],
    }
    assert modello.chiamate[0].istruzioni.startswith(CHAT["instructions"])


def test_chat_errore_del_provider_502(client, modello):
    richiesta = httpx.Request("POST", "https://api.openai.com/v1/responses")
    modello.passi.append(openai.APIConnectionError(request=richiesta))
    risposta = client.post("/agents/demo/chat", json=CHAT)
    assert risposta.status_code == 502
    assert "OpenAI" in risposta.json()["detail"]


def test_chat_senza_chiave_openai_502_senza_dettagli_di_configurazione(client, imposta):
    imposta(openai_api_key="")
    risposta = client.post("/agents/demo/chat", json=CHAT)
    assert risposta.status_code == 502
    assert risposta.json() == {"detail": ModelloNonDisponibile.MESSAGGIO}
    assert "OPENAI_API_KEY" not in risposta.text


@pytest.mark.parametrize(
    ("tipo", "stato"),
    [(openai.AuthenticationError, 401), (openai.PermissionDeniedError, 403)],
    ids=["chiave-rifiutata", "permesso-negato"],
)
def test_chat_con_la_chiave_rifiutata_502_generico_e_causa_nel_log(
    client, modello, caplog, tipo, stato
):
    richiesta = httpx.Request("POST", "https://api.openai.com/v1/responses")
    risposta_openai = httpx.Response(stato, request=richiesta)
    modello.passi.append(tipo("Incorrect API key provided", response=risposta_openai, body=None))

    risposta = client.post("/agents/demo/chat", json=CHAT)

    assert risposta.status_code == 502
    assert risposta.json() == {"detail": ModelloNonDisponibile.MESSAGGIO}
    assert "Incorrect API key" not in risposta.text
    errori = [r.getMessage() for r in caplog.records if r.levelno == logging.ERROR]
    assert len(errori) == 1
    assert "Incorrect API key provided" in errori[0]


def test_cancella_sessione_idempotente_e_la_chat_dimentica(client, modello):
    modello.passi += [scrive("Piacere, Marco."), scrive("Non lo so.")]
    client.post("/agents/demo/chat", json=CHAT | {"message": "Mi chiamo Marco."})

    assert client.delete("/agents/demo/sessions/s1").status_code == 204
    assert client.delete("/agents/demo/sessions/s1").status_code == 204

    client.post("/agents/demo/chat", json=CHAT | {"message": "Come mi chiamo?"})
    assert modello.chiamate[1].messaggi() == [("user", "Come mi chiamo?")]


def test_cancella_agente_idempotente(client):
    carica(client, "demo", "faq.md", b"Il prestito dura 30 giorni.")
    carica(client, "demo", NOTE_SPESE.name, NOTE_SPESE.read_bytes(), tipo="tables")

    assert client.delete("/agents/demo").status_code == 204
    assert client.get("/agents/demo/documents").json() == {"documents": []}
    assert client.get("/agents/demo/tables").json() == {"tables": []}
    assert client.delete("/agents/demo").status_code == 204


# --- copia di un agente ----------------------------------------------------------------------


def test_clone_copia_documenti_e_tabelle(client):
    carica(client, "demo", "faq.md", b"Il prestito dura 30 giorni.")
    carica(client, "demo", NOTE_SPESE.name, NOTE_SPESE.read_bytes(), tipo="tables")

    risposta = client.post("/agents/copia/clone", json={"from": "demo"})

    assert risposta.status_code == 201
    assert risposta.json() == {"agent_id": "copia", "from": "demo", "documents": 1, "tables": 1}
    for elenco in ("documents", "tables"):
        assert (
            client.get(f"/agents/copia/{elenco}").json()
            == client.get(f"/agents/demo/{elenco}").json()
        )
    sql = {"sql": "SELECT COUNT(*), ROUND(SUM(importo), 2) FROM note_spese"}
    copia = client.post("/agents/copia/sql", json=sql).json()
    assert copia == client.post("/agents/demo/sql", json=sql).json()

    di_nuovo = client.post("/agents/copia/clone", json={"from": "demo"})
    assert di_nuovo.status_code == 409
    assert "ha già documenti o tabelle" in di_nuovo.json()["detail"]


def test_clone_da_un_agente_senza_dati_404(client):
    risposta = client.post("/agents/copia/clone", json={"from": "mai-visto"})
    assert risposta.status_code == 404
    assert risposta.json() == {"detail": "L'agente da copiare non ha documenti né tabelle."}


def test_clone_su_se_stesso_422(client):
    carica(client, "demo", "faq.md", b"Il prestito dura 30 giorni.")
    risposta = client.post("/agents/demo/clone", json={"from": "demo"})
    assert risposta.status_code == 422
    assert risposta.json() == {"detail": "Un agente non si può copiare su sé stesso."}


@pytest.mark.parametrize(
    "corpo",
    [{}, {"from": "Demo"}, {"from": "../demo"}, {"from": ""}, {"sorgente": "demo"}],
    ids=["senza-from", "maiuscole", "percorso", "vuoto", "nome-python"],
)
def test_clone_con_from_non_valido_422(client, corpo):
    risposta = client.post("/agents/copia/clone", json=corpo)
    assert risposta.status_code == 422
    assert isinstance(risposta.json()["detail"], list)  # il formato di FastAPI


def test_500_senza_dettagli_interni(imposta, monkeypatch):
    imposta(internal_api_key=CHIAVE)

    def guasto(agent_id: str) -> list:
        raise RuntimeError("/percorso/segreto/chroma.sqlite3 è bloccato")

    monkeypatch.setattr(ingest, "lista_documenti", guasto)
    client = TestClient(
        create_app(), headers={"X-Internal-Key": CHIAVE}, raise_server_exceptions=False
    )
    risposta = client.get("/agents/demo/documents")
    assert risposta.status_code == 500
    assert risposta.json() == {"detail": MESSAGGIO_ERRORE_INTERNO}
    assert "segreto" not in risposta.text


# --- log --------------------------------------------------------------------------------------


@contextmanager
def logging_come_sotto_uvicorn() -> Iterator[logging.Logger]:
    """Il logging come lo lascia uvicorn: nessun handler sul root, né sul logger "app".

    Dentro il test e non in una fixture: pytest rimette i suoi handler sul root a ogni fase.
    """
    radice, logger_app = logging.getLogger(), logging.getLogger("app")
    tolti = radice.handlers[:]
    for handler in tolti:
        radice.removeHandler(handler)
    try:
        yield logger_app
    finally:
        for handler in logger_app.handlers[:]:
            logger_app.removeHandler(handler)
        logger_app.setLevel(logging.NOTSET)
        for handler in tolti:
            radice.addHandler(handler)


def test_i_log_info_di_app_compaiono_una_volta_e_le_librerie_restano_zitte(capsys):
    with logging_come_sotto_uvicorn() as logger_app:
        create_app()
        create_app()  # due app nello stesso processo (test, --reload): un solo handler

        logging.getLogger("app.core.ingest").info("demo: faq.md indicizzato in 3 chunk")
        logging.getLogger("httpx").info("HTTP Request: POST https://api.openai.com/v1/embeddings")

        assert len(logger_app.handlers) == 1
        assert logging.getLogger().handlers == []  # il root resta quello di uvicorn
    assert capsys.readouterr().err == (
        "INFO:     app.core.ingest: demo: faq.md indicizzato in 3 chunk\n"
    )


def test_logging_gia_configurato_non_si_tocca():
    # Sotto pytest il root ha già i suoi handler: i log di app.* arrivano a caplog.
    create_app()
    assert logging.getLogger("app").handlers == []
