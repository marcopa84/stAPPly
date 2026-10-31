import os
from pathlib import Path

import openai

from app.config import BACKEND_DIR, Settings
from tests.conftest import CHIAVE_OPENAI_FINTA


def test_data_dir_relativa_parte_da_backend():
    assert Settings(_env_file=None, data_dir=Path("data")).data_dir == BACKEND_DIR / "data"


def test_data_dir_assoluta_resta_com_e(tmp_path):
    assert Settings(_env_file=None, data_dir=tmp_path).data_dir == tmp_path


def test_env_file_con_variabili_per_altri(tmp_path):
    env = tmp_path / ".env"
    env.write_text("VARIABILE_DI_ALTRI=1\nMAX_UPLOAD_MB=3\nSQL_CONSOLE_LIMIT=7\n")
    impostazioni = Settings(_env_file=env)
    assert impostazioni.max_upload_bytes == 3 * 1024 * 1024
    assert impostazioni.sql_console_limit == 7


def test_i_test_usano_sempre_la_chiave_openai_finta(impostazioni, tmp_path):
    # Solo booleani nelle assert: se una fallisse, pytest stamperebbe i valori confrontati,
    # e uno potrebbe essere la chiave vera.
    env = tmp_path / ".env"
    env.write_text("OPENAI_API_KEY=sk-chiave-scritta-nel-file\n")
    nell_ambiente = os.environ.get("OPENAI_API_KEY") == CHIAVE_OPENAI_FINTA
    nei_settings = impostazioni.openai_api_key == CHIAVE_OPENAI_FINTA
    vince_sul_file = Settings(_env_file=env).openai_api_key == CHIAVE_OPENAI_FINTA
    client_senza_chiave = openai.OpenAI().api_key == CHIAVE_OPENAI_FINTA
    assert nell_ambiente, "OPENAI_API_KEY nell'ambiente non è quella finta di conftest"
    assert nei_settings, "get_settings() non restituisce la chiave finta"
    assert vince_sul_file, "una chiave in un .env vince su quella finta"
    assert client_senza_chiave, "un client OpenAI senza api_key non prende la chiave finta"


def test_tracing_spento_di_default_e_riaccendibile_dal_env_file(tmp_path, monkeypatch):
    monkeypatch.delenv("OPENAI_AGENTS_DISABLE_TRACING")  # lo imposta conftest per tutti i test
    assert Settings(_env_file=None).openai_agents_disable_tracing is True
    env = tmp_path / ".env"
    env.write_text("OPENAI_AGENTS_DISABLE_TRACING=false\n")
    assert Settings(_env_file=env).openai_agents_disable_tracing is False


def test_valori_predefiniti():
    impostazioni = Settings(_env_file=None)
    assert (impostazioni.chunk_size, impostazioni.top_k, impostazioni.max_turns) == (500, 4, 10)
    assert impostazioni.max_documents_per_agent == 20
    assert impostazioni.max_tables_per_agent == 10
    assert (impostazioni.query_db_limit, impostazioni.sql_console_limit) == (50, 200)
    assert impostazioni.embedding_batch == 100
    assert impostazioni.embedding_model == "text-embedding-3-small"
