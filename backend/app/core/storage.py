"""Dove stanno i dati degli agenti. Tutto vive sotto DATA_DIR:

    DATA_DIR/
    ├── chroma/              vettori di tutti gli agenti, una collection `agent_<id>` per agente
    ├── sessions.db          memoria delle chat (SQLiteSession), chiave `<agent_id>:<session_id>`
    └── agents/<agent_id>/
        ├── files/           originali dei documenti, salvati come `<document_id><estensione>`
        ├── tables.db        le tabelle dell'agente: l'unico file che query_db apre
        └── tables.json      nome del file e data di caricamento di ogni tabella

I metadati delle tabelle stanno fuori da tables.db di proposito: query_db vede solo i dati.
"""

import shutil
import sqlite3
import threading
from collections.abc import Iterator
from contextlib import closing, contextmanager
from datetime import UTC, datetime
from pathlib import Path

from app.config import get_settings
from app.core import ids

# Le tabelle che SQLiteSession crea in sessions.db (agents/memory/sqlite_session.py, SDK 0.22.3).
# Le chiavi esterne non sono attive, quindi l'ON DELETE CASCADE dichiarato non scatta.
TABELLA_SESSIONI = "agent_sessions"
TABELLA_MESSAGGI = "agent_messages"


def cartella_chroma() -> Path:
    return get_settings().data_dir / "chroma"


def percorso_sessioni() -> Path:
    """Il database delle chat. La cartella viene creata se manca: SQLiteSession crea il
    file, ma non le cartelle che lo contengono."""
    data_dir = get_settings().data_dir
    data_dir.mkdir(parents=True, exist_ok=True)
    return data_dir / "sessions.db"


def cartella_agente(agent_id: str) -> Path:
    """La cartella dell'agente; l'id è controllato perché diventa parte del percorso."""
    return get_settings().data_dir / "agents" / ids.controlla_agent_id(agent_id)


def cartella_file(agent_id: str) -> Path:
    return cartella_agente(agent_id) / "files"


def percorso_tabelle(agent_id: str) -> Path:
    return cartella_agente(agent_id) / "tables.db"


def percorso_meta_tabelle(agent_id: str) -> Path:
    return cartella_agente(agent_id) / "tables.json"


def adesso() -> str:
    """Data e ora attuali in UTC, nel formato del contratto: `2026-10-31T10:12:04Z`."""
    return datetime.now(UTC).strftime("%Y-%m-%dT%H:%M:%SZ")


_blocchi: dict[str, threading.RLock] = {}
_guardia_blocchi = threading.Lock()


@contextmanager
def blocco_agente(agent_id: str) -> Iterator[None]:
    """Serializza le modifiche ai dati di un agente: due upload in parallelo non devono
    superare insieme il limite di file, né sostituire lo stesso documento a metà.

    Vale dentro un processo: il backend gira con un solo worker.
    """
    with _guardia_blocchi:
        blocco = _blocchi.setdefault(agent_id, threading.RLock())
    with blocco:
        yield


def cancella_sessione(agent_id: str, session_id: str) -> None:
    """Dimentica una conversazione. Nessun errore se non c'era."""
    _cancella_sessioni("session_id = ?", (ids.chiave_sessione(agent_id, session_id),))


def cancella_agente(agent_id: str) -> None:
    """Cancella tutto quello che il backend sa di un agente: collection, file, tabelle e
    sessioni di chat. Idempotente: un agente senza dati non è un errore."""
    # Import qui e non in testa: vectorstore usa i percorsi di questo modulo.
    from app.core import vectorstore

    prefisso = f"{ids.controlla_agent_id(agent_id)}:"
    with blocco_agente(agent_id):
        vectorstore.cancella_collection(agent_id)
        cartella = cartella_agente(agent_id)
        if cartella.exists():
            shutil.rmtree(cartella)
        # Gli agent_id non contengono ":", quindi "demo:" non può essere l'inizio di "demo-2:…".
        _cancella_sessioni("substr(session_id, 1, ?) = ?", (len(prefisso), prefisso))


def _cancella_sessioni(condizione: str, parametri: tuple[object, ...]) -> None:
    percorso = percorso_sessioni()
    if not percorso.exists():
        return  # nessuna chat ancora: non serve creare il file
    with closing(sqlite3.connect(percorso, timeout=10)) as con:
        presenti = {riga[0] for riga in con.execute("SELECT name FROM sqlite_master")}
        with con:
            for tabella in (TABELLA_MESSAGGI, TABELLA_SESSIONI):
                if tabella in presenti:
                    con.execute(f"DELETE FROM {tabella} WHERE {condizione}", parametri)
