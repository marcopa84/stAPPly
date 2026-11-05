"""Il titolo di una chat: lo chiede Laravel dopo la prima risposta."""

from fastapi import APIRouter

from app.api.schemi import RichiestaTitolo, Titolo
from app.core import titoli

router = APIRouter(tags=["chat"])


@router.post("/titles", response_model=Titolo)
async def titola(richiesta: RichiestaTitolo) -> dict[str, str]:
    """Un titolo breve per la chat che si è aperta con questo scambio. Non dipende
    dall'agente: è una chiamata al modello senza tool, sui testi che arrivano qui."""
    return {"title": await titoli.genera_titolo(richiesta.message, richiesta.answer)}
