"""Da testo a vettore con l'API di OpenAI: `embed_openai` di aikit/embeddings.py (corso).

Rispetto al corso: modello e dimensione dei lotti da config, logging invece di print, errori
del provider tradotti in ProviderNonDisponibile. Il resto del codice chiama sempre
`embeddings.embed(...)` passando dal modulo, così i test sostituiscono una funzione sola.
"""

import logging
from functools import lru_cache

import openai

from app.config import get_settings
from app.core.errors import ProviderNonDisponibile

logger = logging.getLogger(__name__)

# Molto meno dei 10 minuti predefiniti dell'SDK: Laravel aspetta il backend al massimo 120 s.
# Un lotto lento si riprova una volta sola, quindi resta sotto un minuto (più l'attesa tra i
# due tentativi); con i 2 retry predefiniti arriverebbe a 90 s.
TIMEOUT_SECONDI = 30.0
RIPROVE = 1


@lru_cache
def _client(api_key: str) -> openai.OpenAI:
    return openai.OpenAI(api_key=api_key, timeout=TIMEOUT_SECONDI, max_retries=RIPROVE)


def embed(testi: list[str]) -> list[list[float]]:
    """Gli embedding di `testi`, nello stesso ordine, a lotti di EMBEDDING_BATCH testi."""
    if not testi:
        return []
    settings = get_settings()
    vettori: list[list[float]] = []
    try:
        client = _client(settings.openai_api_key)  # senza chiave solleva già OpenAIError
        for inizio in range(0, len(testi), settings.embedding_batch):
            lotto = testi[inizio : inizio + settings.embedding_batch]
            risposta = client.embeddings.create(model=settings.embedding_model, input=lotto)
            vettori.extend(d.embedding for d in sorted(risposta.data, key=lambda d: d.index))
            logger.info("embedding di %d testi: %d token", len(lotto), risposta.usage.total_tokens)
    except openai.OpenAIError as errore:
        # Chiave mancante o rifiutata: riprovare non serve, va sistemata la configurazione.
        configurazione = not settings.openai_api_key or isinstance(
            errore, openai.AuthenticationError | openai.PermissionDeniedError
        )
        livello = logging.ERROR if configurazione else logging.WARNING
        logger.log(livello, "embedding non riuscito: %s", errore)
        raise ProviderNonDisponibile(
            "Il servizio di embedding (OpenAI) non risponde o ha rifiutato la richiesta: "
            "riprova tra poco."
        ) from errore
    return vettori
