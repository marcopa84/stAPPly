# syntax=docker/dockerfile:1
# stAPPly, backend FastAPI. Le dipendenze vengono da uv.lock, come in CI: l'immagine non
# risolve niente da sé.
FROM python:3.12-slim-bookworm

COPY --from=ghcr.io/astral-sh/uv:0.11.29 /uv /usr/local/bin/uv

ENV UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PYTHON_DOWNLOADS=never \
    UV_PROJECT_ENVIRONMENT=/opt/venv \
    PATH=/opt/venv/bin:$PATH \
    PYTHONUNBUFFERED=1 \
    DATA_DIR=/var/lib/stapply

WORKDIR /app

# Prima solo le dipendenze: questo strato resta in cache finché non cambia uv.lock.
COPY pyproject.toml uv.lock .python-version ./
RUN uv sync --locked --no-dev --no-install-project

COPY app ./app
COPY scripts ./scripts
COPY seed ./seed
COPY docker-entrypoint.sh /usr/local/bin/stapply-entrypoint

# Vettori, tabelle e sessioni stanno in DATA_DIR, che compose.yaml monta come volume: la
# cartella esiste già con il proprietario giusto, così il volume nuovo lo eredita. Il chmod
# rende leggibili i sorgenti all'utente senza privilegi qualunque sia la umask di chi li ha
# copiati (COPY conserva i permessi dei file).
RUN useradd --system --uid 10001 --home-dir /app stapply \
    && chmod -R a+rX app scripts seed \
    && mkdir -p "$DATA_DIR" \
    && chown stapply:stapply "$DATA_DIR"
USER stapply

EXPOSE 8001
# Il primo avvio indicizza gli agenti demo prima di aprire la porta: da qui lo start-period lungo.
HEALTHCHECK --interval=10s --timeout=3s --start-period=90s --retries=6 \
    CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8001/health', timeout=2)"]

ENTRYPOINT ["stapply-entrypoint"]
# Un solo worker: i lock per agente e il client OpenAI in cache valgono dentro un processo.
CMD ["python", "-m", "uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
