"""La chat con un agente e la cancellazione di una sua conversazione."""

from dataclasses import asdict
from typing import Any

from fastapi import APIRouter, status

from app.api.dipendenze import AgentId, SessionId
from app.api.schemi import RichiestaChat, RispostaChat
from app.core import chat, storage

router = APIRouter(prefix="/agents/{agent_id}", tags=["chat"])


@router.post("/chat", response_model=RispostaChat)
async def chatta(agent_id: AgentId, richiesta: RichiestaChat) -> dict[str, Any]:
    """Un turno di chat. `async`: quasi tutto il tempo è attesa del modello, e l'SDK esegue
    i tool (che bloccano) in thread separati."""
    risposta = await chat.rispondi(
        agent_id,
        richiesta.session_id,
        richiesta.message,
        richiesta.instructions,
        richiesta.agent_name,
    )
    return asdict(risposta)


@router.delete("/sessions/{session_id}", status_code=status.HTTP_204_NO_CONTENT)
def cancella_sessione(agent_id: AgentId, session_id: SessionId) -> None:
    """Dimentica una conversazione; 204 anche se non c'era."""
    storage.cancella_sessione(agent_id, session_id)
