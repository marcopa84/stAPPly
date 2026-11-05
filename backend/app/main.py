"""Il backend di stAPPly: l'API che chiama solo Laravel (contratto in docs/api.md).

Avvio: `uv run uvicorn app.main:app --port 8001`. Un solo worker: i blocchi che serializzano
gli upload di un agente valgono dentro un processo (app/core/storage.py).
"""

import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI
from uvicorn.logging import DefaultFormatter

from app.api import agents, chat, documents, tables, titoli
from app.api.dipendenze import controlla_chiave
from app.api.errori import registra_gestori
from app.api.schemi import Salute
from app.config import get_settings

logger = logging.getLogger(__name__)


@asynccontextmanager
async def _ciclo_di_vita(app: FastAPI) -> AsyncIterator[None]:
    if not get_settings().internal_api_key:
        logger.warning("INTERNAL_API_KEY è vuota: ogni richiesta tranne /health avrà 401.")
    yield


def configura_log() -> None:
    """Fa comparire i log INFO di app.* (upload, embedding, errori della chat) sotto uvicorn,
    nel formato dei suoi.

    Uvicorn configura solo i suoi logger e lascia il root senza handler: i messaggi INFO di
    app.* finirebbero nell'handler di riserva di logging, che mostra solo da WARNING in su.
    L'handler va sul logger "app" e non sul root: le librerie (httpx, chromadb) restano zitte
    e i log di uvicorn, che non arrivano al root, non si duplicano. Se il logging è già
    configurato (pytest, `--log-config` di uvicorn) non si tocca niente.
    """
    logger_app = logging.getLogger("app")
    if logger_app.handlers or logging.getLogger().handlers:
        return
    handler = logging.StreamHandler()
    handler.setFormatter(DefaultFormatter("%(levelprefix)s %(name)s: %(message)s"))
    logger_app.addHandler(handler)
    logger_app.setLevel(logging.INFO)


def create_app() -> FastAPI:
    configura_log()
    app = FastAPI(
        title="stAPPly backend",
        summary="Staple your docs. Apply your knowledge.",
        version="0.1.0",
        lifespan=_ciclo_di_vita,
    )
    registra_gestori(app)

    @app.get("/health", response_model=Salute, tags=["servizio"])
    def salute() -> dict[str, str]:
        """Stato del servizio, senza chiave: serve a Laravel e al deploy per sapere se è su."""
        return {"status": "ok"}

    for modulo in (documents, tables, chat, agents, titoli):
        app.include_router(modulo.router, dependencies=[Depends(controlla_chiave)])
    return app


app = create_app()
