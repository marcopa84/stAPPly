"""Un turno di chat: il ciclo `__main__` di solutions/obiettivo4_assistente.py (corso), da
servizio invece che da terminale.

Ogni turno ricostruisce l'agente (istruzioni e tool possono essere cambiati da Laravel o da un
upload) e riprende la memoria da sessions.db con la chiave `<agent_id>:<session_id>`: la stessa
session_id su due agenti diversi resta due conversazioni separate. Al modello arrivano solo gli
ultimi SESSION_HISTORY_TURNS turni (`ultimi_turni`), e un turno ha un tempo massimo.

La risposta porta sempre la traccia (`tools`, `route`), come nel corso, e le `sources`: i chunk
che il modello ha davvero citato tra quelli che cerca_documenti gli ha restituito.
"""

import asyncio
import logging
import re
from collections.abc import Callable
from dataclasses import dataclass
from typing import Any

import openai
from agents import (
    MaxTurnsExceeded,
    Model,
    ModelBehaviorError,
    ModelTimeoutError,
    RunConfig,
    Runner,
    SQLiteSession,
    TResponseInputItem,
)

from app.config import get_settings
from app.core import agent, ids, storage
from app.core.errors import ErroreStapply, ModelloNonDisponibile, ProviderNonDisponibile

logger = logging.getLogger(__name__)

MESSAGGIO_MAX_TURNS = (
    "Non sono riuscito a completare la risposta entro il numero massimo di passaggi. "
    "Prova a riformulare la domanda."
)

# Il tetto di un turno intero (modello, tool, ripetizioni), sotto i 120 s che Laravel aspetta
# il backend (STAPPLY_BACKEND_TIMEOUT). Senza, Laravel mostrerebbe il suo errore mentre il
# backend continua a lavorare e salva nella sessione una risposta che nessuno ha letto. Un
# tool già partito in un thread finisce per conto suo, ma il turno si chiude qui.
TEMPO_MASSIMO_TURNO = 90.0
MESSAGGIO_TEMPO_SCADUTO = "Il modello (OpenAI) non ha risposto in tempo: riprova tra poco."

# Il contenuto di ogni coppia di quadre: ci possono essere più id ("[a-000, b-001]").
_TRA_QUADRE = re.compile(r"\[([^\[\]]+)\]")
_ID_CHUNK = re.compile(r"[a-z0-9-]+")


@dataclass
class RispostaChat:
    """La risposta di un turno, con le chiavi di docs/contract/chat.200.json."""

    answer: str
    status: str  # "ok", oppure "max_turns"
    route: str  # "documenti", "dati", "documenti+dati" o "nessuna"
    tools: list[dict[str, Any]]
    sources: list[dict[str, str]]


async def rispondi(
    agent_id: str,
    session_id: str,
    message: str,
    instructions: str,
    agent_name: str,
    *,
    modello: Model | None = None,
) -> RispostaChat:
    """Fa rispondere l'agente a `message` nella sessione `session_id`.

    `modello` serve ai test; senza, si usa `agent.crea_modello()`. Solleva
    ProviderNonDisponibile se OpenAI non risponde entro TEMPO_MASSIMO_TURNO o rifiuta la
    chiamata (ModelloNonDisponibile se la chiave manca o non è accettata), e
    ContenutoNonValido se gli id non sono validi.
    """
    settings = get_settings()
    chiave = ids.chiave_sessione(agent_id, session_id)
    contesto = agent.ContestoChat()
    sessione = SQLiteSession(chiave, storage.percorso_sessioni())
    scadenza = asyncio.timeout(TEMPO_MASSIMO_TURNO)
    try:
        async with scadenza:
            # Costruire l'agente legge Chroma e SQLite, che bloccano: meglio fuori dall'event loop.
            agente = await asyncio.to_thread(
                agent.costruisci_agente,
                agent_id,
                agent_name,
                instructions,
                modello or agent.crea_modello(),
            )
            risultato = await Runner.run(
                agente,
                message,
                context=contesto,
                session=sessione,
                max_turns=settings.max_turns,
                run_config=RunConfig(
                    tracing_disabled=settings.openai_agents_disable_tracing,
                    workflow_name="stAPPly chat",
                    group_id=chiave,
                    session_input_callback=ultimi_turni(settings.session_history_turns),
                ),
            )
    except MaxTurnsExceeded as errore:
        # Niente risposta, ma la traccia di quello che ha fatto finora serve a capire perché.
        tools = agent.tool_chiamati(errore.run_data.new_items if errore.run_data else [])
        return RispostaChat(MESSAGGIO_MAX_TURNS, "max_turns", agent.strada(tools), tools, [])
    except Exception as errore:
        if scadenza.expired():
            dominio: ErroreStapply | None = ProviderNonDisponibile(MESSAGGIO_TEMPO_SCADUTO)
        else:
            dominio = errore_di_dominio(errore)
        if dominio is None or dominio is errore:
            raise
        if isinstance(dominio, ModelloNonDisponibile):
            # Riprovare non serve: va sistemata la configurazione, e chi la gestisce legge qui.
            logger.error(
                "chat %s: OpenAI rifiuta la chiamata (OPENAI_API_KEY o permessi del progetto): %r",
                chiave,
                errore,
            )
        else:
            logger.warning("chat %s non riuscita: %r", chiave, errore)
        raise dominio from errore
    finally:
        sessione.close()

    answer = str(risultato.final_output)
    tools = agent.tool_chiamati(risultato.new_items)
    return RispostaChat(
        answer, "ok", agent.strada(tools), tools, fonti(answer, contesto.chunk_restituiti)
    )


def ultimi_turni(
    turni: int,
) -> Callable[[list[TResponseInputItem], list[TResponseInputItem]], list[TResponseInputItem]]:
    """Il `session_input_callback` dell'SDK che manda al modello solo gli ultimi `turni` turni
    della sessione, seguiti dal messaggio nuovo. In sessions.db resta tutta la storia.

    Senza un limite ogni messaggio rimanda al modello l'intera conversazione, output dei tool
    compresi: il costo di un turno cresce con la chat, finché supera la finestra di contesto.
    Si taglia solo all'inizio di un turno, cioè prima di un messaggio dell'utente: un limite
    sul numero di item (SessionSettings) potrebbe cadere tra un `reasoning` e la
    `function_call` che ne dipende, o tra una chiamata e il suo output.
    """

    def combina(
        storia: list[TResponseInputItem], nuovi: list[TResponseInputItem]
    ) -> list[TResponseInputItem]:
        inizi = [i for i, item in enumerate(storia) if item.get("role") == "user"]
        primo = inizi[-turni] if len(inizi) > turni else 0
        return storia[primo:] + nuovi

    return combina


def errore_di_dominio(errore: BaseException) -> ErroreStapply | None:
    """L'errore da mostrare all'utente, cercato lungo la catena delle cause: l'SDK avvolge in
    un UserError le eccezioni dei tool. None se è un errore imprevisto (un bug)."""
    visti: set[int] = set()
    corrente: BaseException | None = errore
    while corrente is not None and id(corrente) not in visti:
        visti.add(id(corrente))
        if isinstance(corrente, ErroreStapply):
            return corrente
        # Prima di OpenAIError, di cui sono sottoclassi: qui il messaggio non dice il perché.
        if isinstance(corrente, openai.AuthenticationError | openai.PermissionDeniedError):
            return ModelloNonDisponibile()
        if isinstance(corrente, openai.OpenAIError | ModelTimeoutError):
            return ProviderNonDisponibile(
                "Il modello (OpenAI) non risponde o ha rifiutato la richiesta: riprova tra poco."
            )
        if isinstance(corrente, ModelBehaviorError):
            # Un tool inesistente o argomenti illeggibili: un incidente del modello, si riprova.
            return ProviderNonDisponibile("Il modello ha risposto in modo inatteso: riprova.")
        corrente = corrente.__cause__ or corrente.__context__
    return None


def fonti(answer: str, chunk: dict[str, dict[str, Any]]) -> list[dict[str, str]]:
    """I chunk citati in `answer` (id tra [ ]), in ordine di citazione e senza doppioni, tra
    quelli restituiti da cerca_documenti in questo turno: un id inventato non diventa una fonte."""
    citati: list[str] = []
    for gruppo in _TRA_QUADRE.findall(answer):
        for id_chunk in _ID_CHUNK.findall(gruppo):
            if id_chunk in chunk and id_chunk not in citati:
                citati.append(id_chunk)
    return [
        {
            "id": id_chunk,
            "document_id": chunk[id_chunk]["document_id"],
            "filename": chunk[id_chunk]["source"],
            "text": chunk[id_chunk]["testo"],
        }
        for id_chunk in citati
    ]
