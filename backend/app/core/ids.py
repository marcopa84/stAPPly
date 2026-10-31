"""Identificatori e nomi: le regole di docs/api.md, in funzioni pure.

Tutto quello che arriva dal client (id negli URL, nomi dei file, intestazioni delle tabelle)
passa da qui prima di diventare un percorso, il nome di una collection o un identificatore SQL.
"""

import re
import unicodedata
from collections.abc import Sequence
from pathlib import PurePosixPath

from app.core.errors import ContenutoNonValido

# Le stesse espressioni di docs/api.md: l'API le può usare anche per validare i parametri.
AGENT_ID_PATTERN = r"^[a-z0-9](?:[a-z0-9-]{0,48}[a-z0-9])?$"
SESSION_ID_PATTERN = r"^[a-zA-Z0-9_-]{1,64}$"
DOCUMENT_ID_PATTERN = r"^[a-z0-9]+(?:-[a-z0-9]+)*$"
# I nomi che produce `nome_tabella()`: mai una cifra in testa (c'è il prefisso `t_`).
TABLE_PATTERN = r"^[a-z_][a-z0-9_]{0,59}$"

MAX_DOCUMENT_ID = 80
MAX_IDENTIFICATORE = 60


def agent_id_valido(valore: str) -> bool:
    # fullmatch e non match: con match "$" accetterebbe anche un "\n" finale.
    return re.fullmatch(AGENT_ID_PATTERN, valore) is not None


def session_id_valido(valore: str) -> bool:
    return re.fullmatch(SESSION_ID_PATTERN, valore) is not None


def document_id_valido(valore: str) -> bool:
    """True se `valore` può essere un document_id prodotto da `document_id()`."""
    return len(valore) <= MAX_DOCUMENT_ID and re.fullmatch(DOCUMENT_ID_PATTERN, valore) is not None


def controlla_agent_id(agent_id: str) -> str:
    """L'agent_id se è valido; altrimenti ContenutoNonValido (diventa parte di percorsi)."""
    if not agent_id_valido(agent_id):
        raise ContenutoNonValido(
            "Identificativo dell'agente non valido: sono ammesse lettere minuscole, cifre e "
            "trattini (al massimo 50 caratteri, niente trattino all'inizio o alla fine)."
        )
    return agent_id


def controlla_session_id(session_id: str) -> str:
    if not session_id_valido(session_id):
        raise ContenutoNonValido(
            "Identificativo della sessione non valido: sono ammesse lettere, cifre, trattini e "
            "trattini bassi (al massimo 64 caratteri)."
        )
    return session_id


def chiave_sessione(agent_id: str, session_id: str) -> str:
    """La chiave della sessione in sessions.db: `<agent_id>:<session_id>`."""
    return f"{controlla_agent_id(agent_id)}:{controlla_session_id(session_id)}"


def nome_collection(agent_id: str) -> str:
    """La collection Chroma dell'agente: una per agente, così si cancella in un colpo."""
    return f"agent_{controlla_agent_id(agent_id)}"


def nome_file(filename: str) -> str:
    """Il nome del file caricato, ridotto all'ultimo componente e senza caratteri di controllo.

    Il nome arriva dal client: qualche browser manda il percorso completo, e un nome come
    `../../app/main.py` non deve mai diventare un percorso.
    """
    nome = filename.replace("\\", "/").rsplit("/", 1)[-1]
    nome = unicodedata.normalize("NFC", nome)
    nome = "".join(c for c in nome if unicodedata.category(c)[0] != "C").strip()
    if nome in ("", ".", ".."):
        raise ContenutoNonValido("Il file caricato non ha un nome valido.")
    return nome


def _radice(filename: str) -> str:
    """Il nome del file senza cartelle e senza estensione."""
    return PurePosixPath(filename.replace("\\", "/")).stem


def _senza_accenti(testo: str) -> str:
    """Minuscolo e senza accenti: `Città` → `citta`."""
    testo = unicodedata.normalize("NFKD", testo.lower())
    return "".join(c for c in testo if not unicodedata.combining(c))


def document_id(filename: str) -> str:
    """`Regolamento Ferie (2026).pdf` → `regolamento-ferie-2026` (regola di docs/api.md)."""
    testo = re.sub(r"[^a-z0-9]+", "-", _senza_accenti(_radice(filename))).strip("-")
    return testo[:MAX_DOCUMENT_ID].rstrip("-") or "documento"


def chunk_id(document_id: str, indice: int) -> str:
    """L'id che il modello cita: `<document_id>-000`, `-001`, …"""
    return f"{document_id}-{indice:03d}"


def _identificatore(testo: str) -> str | None:
    """Minuscolo e senza accenti, sequenze fuori da [a-z0-9_] → `_`, al massimo 60 caratteri,
    `t_` davanti a una cifra o a `sqlite_`. None se non resta né una lettera né una cifra.

    Gli accenti si tolgono perché il modello scrive i nomi nelle query: `quantita` non si
    sbaglia, `quantit_` sì. SQLite riserva i nomi `sqlite_…` alle sue tabelle interne
    (CREATE TABLE fallirebbe); per le colonne vale la stessa regola, per semplicità.
    """
    nome = re.sub(r"[^a-z0-9_]+", "_", _senza_accenti(testo))
    if not re.search(r"[a-z0-9]", nome):
        return None
    if nome[0].isdigit() or nome.startswith("sqlite_"):
        nome = "t_" + nome
    return nome[:MAX_IDENTIFICATORE]


def nome_tabella(filename: str) -> str:
    """`Note spese.csv` → `note_spese`, `Città.csv` → `citta` (regola di docs/api.md)."""
    return _identificatore(_radice(filename)) or "tabella"


def nomi_colonne(intestazione: Sequence[object]) -> list[str]:
    """Le intestazioni rese identificatori SQL: stessa regola delle tabelle, `colonna_N` per
    un'intestazione vuota (N è la posizione, da 1), doppioni con `_2`, `_3`, …"""
    nomi: list[str] = []
    for posizione, valore in enumerate(intestazione, start=1):
        testo = "" if valore is None else str(valore).strip()
        base = _identificatore(testo) or f"colonna_{posizione}"
        nome, copia = base, 1
        while nome in nomi:
            copia += 1
            suffisso = f"_{copia}"
            nome = base[: MAX_IDENTIFICATORE - len(suffisso)] + suffisso
        nomi.append(nome)
    return nomi
