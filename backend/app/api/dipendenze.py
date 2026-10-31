"""Quello che tutti i router condividono: la chiave interna, gli id nei percorsi, l'upload."""

import secrets
from typing import Annotated

from fastapi import Header, HTTPException, Path, UploadFile, status

from app.config import get_settings
from app.core import ids
from app.core.errors import FileTroppoGrande

# Le regex di docs/api.md: un id fuori regola si ferma qui con un 422.
AgentId = Annotated[str, Path(pattern=ids.AGENT_ID_PATTERN)]
SessionId = Annotated[str, Path(pattern=ids.SESSION_ID_PATTERN)]
DocumentId = Annotated[str, Path(pattern=ids.DOCUMENT_ID_PATTERN, max_length=ids.MAX_DOCUMENT_ID)]
NomeTabella = Annotated[str, Path(pattern=ids.TABLE_PATTERN)]

PEZZO_UPLOAD = 1024 * 1024


def controlla_chiave(x_internal_key: Annotated[str | None, Header()] = None) -> None:
    """401 se l'header X-Internal-Key manca o non è la chiave condivisa con Laravel.

    Con INTERNAL_API_KEY vuota il servizio rifiuta tutto: una chiave dimenticata non deve
    aprire le porte a chiunque.
    """
    attesa = get_settings().internal_api_key
    # In bytes: compare_digest su stringhe non ASCII solleva TypeError (sarebbe un 500).
    if not (
        attesa
        and x_internal_key is not None
        and secrets.compare_digest(x_internal_key.encode(), attesa.encode())
    ):
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Chiave interna mancante o non valida.")


def leggi_upload(file: UploadFile) -> tuple[str, bytes]:
    """Nome e contenuto del file caricato, letti a pezzi: oltre MAX_UPLOAD_MB ci si ferma con
    un 413 senza leggere il resto. Starlette ha già messo su disco i file grandi."""
    settings = get_settings()
    pezzi: list[bytes] = []
    letti = 0
    while pezzo := file.file.read(PEZZO_UPLOAD):
        letti += len(pezzo)
        if letti > settings.max_upload_bytes:
            raise FileTroppoGrande(f"Il file supera il limite di {settings.max_upload_mb} MB.")
        pezzi.append(pezzo)
    return file.filename or "", b"".join(pezzi)
