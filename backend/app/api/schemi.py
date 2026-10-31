"""I corpi di richiesta e risposta di docs/api.md, come modelli Pydantic.

Le risposte le validano prima di uscire (una chiave mancante è un 500 qui, non un errore in
Laravel) e finiscono nello schema OpenAPI di /docs.
"""

from typing import Any, Literal

from pydantic import BaseModel, Field

from app.core import ids


class Salute(BaseModel):
    status: Literal["ok"]


class Documento(BaseModel):
    document_id: str
    filename: str
    chunks: int
    size_bytes: int
    uploaded_at: str


class DocumentoCaricato(Documento):
    replaced: bool


class ElencoDocumenti(BaseModel):
    documents: list[Documento]


class Colonna(BaseModel):
    name: str
    type: Literal["INTEGER", "REAL", "TEXT"]


class Tabella(BaseModel):
    table: str
    filename: str
    rows: int
    columns: list[Colonna]
    uploaded_at: str


class TabellaCaricata(Tabella):
    replaced: bool


class ElencoTabelle(BaseModel):
    tables: list[Tabella]


class RichiestaSql(BaseModel):
    sql: str = Field(min_length=1, max_length=10_000)


class RisultatoSql(BaseModel):
    columns: list[str]
    rows: list[list[Any]]
    truncated: bool


class RichiestaClone(BaseModel):
    # "from" è una parola riservata di Python: nel codice il campo si chiama `sorgente`.
    sorgente: str = Field(alias="from", pattern=ids.AGENT_ID_PATTERN)


class AgenteClonato(BaseModel):
    agent_id: str
    sorgente: str = Field(alias="from")
    documents: int
    tables: int


class RichiestaChat(BaseModel):
    session_id: str = Field(pattern=ids.SESSION_ID_PATTERN)
    message: str = Field(min_length=1, max_length=4000)
    instructions: str = Field(default="", max_length=8000)
    agent_name: str = Field(min_length=1, max_length=100)


class ToolChiamato(BaseModel):
    name: str
    arguments: dict[str, Any]


class Fonte(BaseModel):
    id: str
    document_id: str
    filename: str
    text: str


class RispostaChat(BaseModel):
    answer: str
    status: Literal["ok", "max_turns"]
    route: Literal["documenti", "dati", "documenti+dati", "nessuna"]
    tools: list[ToolChiamato]
    sources: list[Fonte]
