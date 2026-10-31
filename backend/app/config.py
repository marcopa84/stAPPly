"""Impostazioni del backend, lette da backend/.env (e dalle variabili d'ambiente, che vincono).

Il resto del codice le legge sempre con `get_settings()` al momento dell'uso, mai all'import:
così i test possono puntare DATA_DIR su una cartella temporanea (vedi tests/conftest.py).

Attenzione: pydantic-settings non copia i valori in `os.environ`, dove l'SDK degli agenti cerca
OPENAI_API_KEY e OPENAI_AGENTS_DISABLE_TRACING. Per questo app/core/agent.py passa la chiave al
client del modello e chat.py passa il tracing a ogni run (`RunConfig`), senza toccare l'ambiente.
"""

from functools import lru_cache
from pathlib import Path

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

BACKEND_DIR = Path(__file__).resolve().parent.parent


class Settings(BaseSettings):
    # extra="ignore": una variabile in più nel .env non deve impedire l'avvio.
    model_config = SettingsConfigDict(
        env_file=BACKEND_DIR / ".env", env_file_encoding="utf-8", extra="ignore"
    )

    openai_api_key: str = ""
    internal_api_key: str = ""
    data_dir: Path = Path("data")
    model: str = "gpt-5.6-luna"
    embedding_model: str = "text-embedding-3-small"
    embedding_batch: int = 100
    chunk_size: int = 500
    top_k: int = 4
    max_turns: int = 10
    # I turni precedenti della sessione che arrivano al modello (app/core/chat.py).
    session_history_turns: int = Field(default=10, ge=1)
    max_upload_mb: int = 10
    # Oltre, un documento costerebbe troppi embedding e troppa memoria: un .docx di pochi KB
    # si può gonfiare fino a FATTORE_DECOMPRESSIONE × MAX_UPLOAD_MB di testo.
    max_chunks_per_document: int = 2000
    max_documents_per_agent: int = 20
    max_tables_per_agent: int = 10
    query_db_limit: int = 50
    sql_console_limit: int = 200
    # Stesso nome della variabile dell'SDK. Spento di default: le tracce porterebbero alla
    # dashboard OpenAI domande, passaggi dei documenti e righe delle tabelle.
    openai_agents_disable_tracing: bool = True

    @field_validator("data_dir")
    @classmethod
    def _relativa_a_backend(cls, valore: Path) -> Path:
        # Relativa a backend/ e non alla cwd: uvicorn, pytest e gli script vedono gli stessi dati.
        valore = valore.expanduser()
        return valore if valore.is_absolute() else BACKEND_DIR / valore

    @property
    def max_upload_bytes(self) -> int:
        return self.max_upload_mb * 1024 * 1024


@lru_cache
def get_settings() -> Settings:
    """Le impostazioni del processo, lette una volta sola (i test svuotano la cache)."""
    return Settings()
