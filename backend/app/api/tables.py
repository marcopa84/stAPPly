"""Tabelle di un agente: upload di CSV ed Excel, elenco, cancellazione e console SQL.

Endpoint `def`: SQLite e openpyxl bloccano, quindi vanno nel threadpool di FastAPI.
"""

from typing import Any

from fastapi import APIRouter, UploadFile, status

from app.api.dipendenze import AgentId, NomeTabella, leggi_upload
from app.api.schemi import ElencoTabelle, RichiestaSql, RisultatoSql, TabellaCaricata
from app.config import get_settings
from app.core import tables

router = APIRouter(prefix="/agents/{agent_id}", tags=["tabelle"])


@router.post("/tables", status_code=status.HTTP_201_CREATED, response_model=TabellaCaricata)
def carica_tabella(agent_id: AgentId, file: UploadFile) -> dict[str, Any]:
    """Importa un .csv o il primo foglio di un .xlsx; lo stesso nome di tabella la sostituisce."""
    nome, contenuto = leggi_upload(file)
    return tables.importa_tabella(agent_id, nome, contenuto)


@router.get("/tables", response_model=ElencoTabelle)
def elenca_tabelle(agent_id: AgentId) -> dict[str, Any]:
    return {"tables": tables.lista_tabelle(agent_id)}


@router.delete("/tables/{table}", status_code=status.HTTP_204_NO_CONTENT)
def cancella_tabella(agent_id: AgentId, table: NomeTabella) -> None:
    tables.cancella_tabella(agent_id, table)


@router.post("/sql", response_model=RisultatoSql)
def esegui_sql(agent_id: AgentId, richiesta: RichiestaSql) -> dict[str, Any]:
    """La stessa esecuzione in sola lettura di query_db, con il limite di righe della console:
    serve a verificare a mano i numeri dell'agente."""
    return tables.esegui_select(agent_id, richiesta.sql, get_settings().sql_console_limit)
