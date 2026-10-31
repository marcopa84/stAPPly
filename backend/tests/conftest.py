"""Fixture comuni: ogni test ha la sua DATA_DIR, una chiave OpenAI finta, embedding finti e
nessun accesso alla rete."""

import asyncio
import io
import json
import math
import re
import socket
import zipfile
import zlib
from collections.abc import Callable, Iterator
from pathlib import Path
from typing import Any

import pytest
from agents import SQLiteSession
from chromadb.api.client import SharedSystemClient

from app import config
from app.core import embeddings, ingest, storage, tables, vectorstore

BACKEND = Path(__file__).resolve().parent.parent
SEED = BACKEND / "seed"
NOTE_SPESE = SEED / "valtesa" / "tabelle" / "note_spese.csv"
CONTRATTO = BACKEND.parent / "docs" / "contract"
DOCUMENTI_SEED = sorted(p for p in SEED.glob("*/documenti/*") if not p.name.startswith("."))
DIMENSIONI = 64
# La chiave OpenAI di ogni test: backend/.env può contenere quella vera, e nessun test deve
# poterla usare, nemmeno se sfugge al blocco della rete o costruisce un client da solo.
CHIAVE_OPENAI_FINTA = "sk-test-finta-stapply"


def embed_finto(testi: list[str]) -> list[list[float]]:
    """Bag of words con hashing, normalizzato: deterministico, e una ricerca con le parole di
    un chunk trova davvero quel chunk."""
    vettori = []
    for testo in testi:
        vettore = [0.0] * DIMENSIONI
        for parola in re.findall(r"\w+", testo.lower()):
            # crc32 e non hash(): hash() cambia a ogni processo.
            vettore[zlib.crc32(parola.encode()) % DIMENSIONI] += 1.0
        norma = math.sqrt(sum(x * x for x in vettore))
        if norma == 0:  # nessuna parola: un vettore nullo darebbe distanze NaN
            vettore[0] = norma = 1.0
        vettori.append([x / norma for x in vettore])
    return vettori


def contratto(nome: str) -> dict[str, Any]:
    """Un esempio di docs/contract/."""
    return json.loads((CONTRATTO / nome).read_text(encoding="utf-8"))


def zip_con(**parti: bytes) -> bytes:
    """Uno zip in memoria: `zip_con(**{"word/document.xml": b"..."})`."""
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as archivio:
        for nome, contenuto in parti.items():
            archivio.writestr(nome, contenuto)
    return buffer.getvalue()


def scrivi_sessione(chiave: str, testo: str) -> None:
    """Una sessione come la scrive la chat: SQLiteSession dell'SDK su sessions.db."""

    async def scrivi() -> None:
        sessione = SQLiteSession(chiave, storage.percorso_sessioni())
        await sessione.add_items([{"role": "user", "content": testo}])
        sessione.close()

    asyncio.run(scrivi())


def messaggi(chiave: str) -> list:
    """Gli item di una sessione in sessions.db."""

    async def leggi() -> list:
        sessione = SQLiteSession(chiave, storage.percorso_sessioni())
        try:
            return await sessione.get_items()
        finally:
            sessione.close()

    return asyncio.run(leggi())


def forma(valore: Any) -> Any:
    """La struttura di un valore JSON (chiavi e tipi), per confrontarla con gli esempi."""
    if isinstance(valore, dict):
        return {chiave: forma(v) for chiave, v in valore.items()}
    if isinstance(valore, list):
        return [forma(valore[0])] if valore else []
    return type(valore).__name__


@pytest.fixture(autouse=True)
def niente_rete(monkeypatch: pytest.MonkeyPatch) -> None:
    def vietato(self: socket.socket, indirizzo: object, *args: object) -> None:
        raise RuntimeError(f"Rete vietata nei test: {indirizzo}")

    monkeypatch.setattr(socket.socket, "connect", vietato)
    monkeypatch.setattr(socket.socket, "connect_ex", vietato)


@pytest.fixture(autouse=True)
def impostazioni(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Iterator[config.Settings]:
    """DATA_DIR in tmp_path e chiave OpenAI finta. Il backend/.env e l'ambiente dello
    sviluppatore non contano."""
    monkeypatch.setitem(config.Settings.model_config, "env_file", None)
    for campo in config.Settings.model_fields:
        monkeypatch.delenv(campo.upper(), raising=False)
    monkeypatch.setenv("DATA_DIR", str(tmp_path / "data"))
    # Nell'ambiente e non solo nei Settings: l'ambiente vince sul .env, e gli SDK di OpenAI
    # cercano la chiave in os.environ quando non gliela passa nessuno. Un test che vuole il
    # backend senza chiave lo dice con imposta(openai_api_key="").
    monkeypatch.setenv("OPENAI_API_KEY", CHIAVE_OPENAI_FINTA)
    # Niente tracce: l'SDK le spedirebbe alla dashboard OpenAI da un thread in background.
    monkeypatch.setenv("OPENAI_AGENTS_DISABLE_TRACING", "true")
    config.get_settings.cache_clear()
    yield config.get_settings()
    config.get_settings.cache_clear()
    # Un client Chroma per test: si liberano i file della DATA_DIR appena usata.
    vectorstore._client.cache_clear()
    SharedSystemClient.clear_system_cache()


@pytest.fixture(autouse=True)
def embedding_finti(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(embeddings, "embed", embed_finto)


@pytest.fixture
def valtesa() -> str:
    """L'agente `valtesa` con due documenti e la tabella note_spese del seed."""
    agent_id = "valtesa"
    for nome in ("circolari-personale-2026.html", "faq-portale-dipendenti.md"):
        contenuto = (SEED / "valtesa" / "documenti" / nome).read_bytes()
        ingest.indicizza_documento(agent_id, nome, contenuto)
    tables.importa_tabella(agent_id, NOTE_SPESE.name, NOTE_SPESE.read_bytes())
    return agent_id


@pytest.fixture
def imposta(monkeypatch: pytest.MonkeyPatch) -> Callable[..., None]:
    """Cambia un'impostazione per il test: `imposta(max_documents_per_agent=2)`."""

    def _imposta(**valori: object) -> None:
        for nome, valore in valori.items():
            monkeypatch.setenv(nome.upper(), str(valore))
        config.get_settings.cache_clear()

    return _imposta
