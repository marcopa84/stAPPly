"""Gli agenti nel loro insieme: la copia (sandbox demo) e la cancellazione, che Laravel chiama
quando l'utente elimina l'agente."""

from typing import Any

from fastapi import APIRouter, status

from app.api.dipendenze import AgentId
from app.api.schemi import AgenteClonato, RichiestaClone
from app.core import clone, storage

router = APIRouter(prefix="/agents/{agent_id}", tags=["agenti"])


@router.post("/clone", status_code=status.HTTP_201_CREATED, response_model=AgenteClonato)
def clona_agente(agent_id: AgentId, richiesta: RichiestaClone) -> dict[str, Any]:
    """Copia in questo agente documenti e tabelle dell'agente `from`, con i vettori già
    calcolati (nessun embedding nuovo); le conversazioni non si copiano."""
    return clone.clona_agente(agent_id, richiesta.sorgente)


@router.delete("", status_code=status.HTTP_204_NO_CONTENT)
def cancella_agente(agent_id: AgentId) -> None:
    """Toglie collection, file, tabelle e conversazioni dell'agente; 204 anche se non aveva dati."""
    storage.cancella_agente(agent_id)
