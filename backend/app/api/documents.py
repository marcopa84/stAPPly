"""Documenti di un agente: upload e indicizzazione, elenco, cancellazione.

Endpoint `def` e non `async def`: lettura dei file, embedding e Chroma bloccano, e FastAPI
esegue le funzioni sincrone nel suo threadpool.
"""

from typing import Any

from fastapi import APIRouter, UploadFile, status

from app.api.dipendenze import AgentId, DocumentId, leggi_upload
from app.api.schemi import DocumentoCaricato, ElencoDocumenti
from app.core import ingest

router = APIRouter(prefix="/agents/{agent_id}/documents", tags=["documenti"])


@router.post("", status_code=status.HTTP_201_CREATED, response_model=DocumentoCaricato)
def carica_documento(agent_id: AgentId, file: UploadFile) -> dict[str, Any]:
    """Indicizza un documento (.txt .md .pdf .docx .html); lo stesso document_id lo sostituisce."""
    nome, contenuto = leggi_upload(file)
    return ingest.indicizza_documento(agent_id, nome, contenuto)


@router.get("", response_model=ElencoDocumenti)
def elenca_documenti(agent_id: AgentId) -> dict[str, Any]:
    return {"documents": ingest.lista_documenti(agent_id)}


@router.delete("/{document_id}", status_code=status.HTTP_204_NO_CONTENT)
def cancella_documento(agent_id: AgentId, document_id: DocumentId) -> None:
    ingest.cancella_documento(agent_id, document_id)
