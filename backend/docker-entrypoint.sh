#!/bin/sh
# Avvio del backend nel container: controlla la chiave interna e, al primo avvio, indicizza gli
# agenti demo; poi passa al comando del container (uvicorn).
set -e

if [ -z "${INTERNAL_API_KEY:-}" ]; then
    echo "stapply: INTERNAL_API_KEY è vuota e il backend rifiuterebbe ogni richiesta." >&2
    echo "stapply: copia backend/.env.example in backend/.env (e frontend/.env.example in frontend/.env)." >&2
    exit 1
fi

# Gli agenti demo si indicizzano una volta sola: il volume li conserva per gli avvii dopo. Costa
# una frazione di centesimo di embedding, quindi serve la chiave OpenAI; senza, o se OpenAI
# rifiuta la chiamata, il backend parte lo stesso e la demo resta vuota.
if [ ! -f "$DATA_DIR/agents/demo-valtesa/tables.db" ]; then
    if [ -n "${OPENAI_API_KEY:-}" ]; then
        python -m scripts.seed_demo || echo "stapply: seed demo non riuscito, la demo resta vuota." >&2
    else
        echo "stapply: senza OPENAI_API_KEY gli agenti demo restano vuoti e la chat risponde 502." >&2
    fi
fi

exec "$@"
