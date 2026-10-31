"""Un modello finto per i test della chat: implementa l'interfaccia Model dell'SDK e risponde
con passi scritti a mano, senza rete.

Ogni passo è la risposta a una chiamata del modello: una lista di item di output (chiamate di
tool, messaggi), una funzione che la costruisce leggendo la chiamata (per "leggere" l'output
di un tool, come farebbe un modello vero) oppure un'eccezione da sollevare. Le chiamate
restano registrate: i test guardano istruzioni, tool e memoria che arrivano al modello.
"""

import copy
import itertools
import json
import re
from collections.abc import AsyncIterator, Callable
from dataclasses import dataclass
from typing import Any

from agents import FunctionTool, Model, ModelResponse, ModelTracing, Tool, Usage
from agents.testing import assistant_message, function_call

Passo = list[Any] | Callable[["Chiamata"], list[Any]] | BaseException

_call_id = itertools.count(1)


@dataclass
class Chiamata:
    """Quello che il modello ha ricevuto in una chiamata."""

    istruzioni: str | None
    input: list[dict[str, Any]]
    tools: list[Tool]
    tracing: ModelTracing

    def tool(self, nome: str) -> FunctionTool:
        return next(t for t in self.tools if isinstance(t, FunctionTool) and t.name == nome)

    @property
    def nomi_tool(self) -> set[str]:
        return {t.name for t in self.tools if isinstance(t, FunctionTool)}

    def messaggi(self) -> list[tuple[str, str]]:
        """I messaggi della conversazione come (ruolo, testo), memoria compresa."""
        messaggi = []
        for item in self.input:
            if "role" not in item:
                continue
            contenuto = item["content"]
            if isinstance(contenuto, list):
                contenuto = "".join(parte.get("text", "") for parte in contenuto)
            messaggi.append((item["role"], contenuto))
        return messaggi

    def output_tool(self) -> list[str]:
        """Gli output dei tool, nell'ordine in cui sono arrivati al modello."""
        return [item["output"] for item in self.input if item.get("type") == "function_call_output"]


class ModelloFinto(Model):
    def __init__(self, *passi: Passo) -> None:
        self.passi = list(passi)
        self.chiamate: list[Chiamata] = []

    async def get_response(
        self,
        system_instructions,
        input,
        model_settings,
        tools,
        output_schema,
        handoffs,
        tracing,
        *,
        previous_response_id,
        conversation_id,
        prompt,
    ) -> ModelResponse:
        if isinstance(input, str):
            input = [{"role": "user", "content": input}]
        chiamata = Chiamata(system_instructions, copy.deepcopy(input), list(tools), tracing)
        self.chiamate.append(chiamata)
        if not self.passi:
            raise AssertionError("Il modello finto è stato chiamato più volte del previsto.")
        passo = self.passi.pop(0)
        if isinstance(passo, BaseException):
            raise passo
        output = passo(chiamata) if callable(passo) else passo
        return ModelResponse(output=output, usage=Usage(), response_id=None)

    def stream_response(self, *args: Any, **kwargs: Any) -> AsyncIterator[Any]:
        raise NotImplementedError("La chat di stAPPly non usa lo streaming.")


def chiama(nome: str, argomenti: dict[str, Any] | str) -> Any:
    """La chiamata di un tool. Una stringa passa così com'è (per mandare JSON non valido)."""
    testo = argomenti if isinstance(argomenti, str) else json.dumps(argomenti)
    return function_call(nome, testo, call_id=f"call_{next(_call_id)}")


def scrive(testo: str) -> list[Any]:
    """Il passo finale: un messaggio dell'assistente."""
    return [assistant_message(testo)]


def cita_il_primo_passaggio(chiamata: Chiamata) -> list[Any]:
    """Risponde citando il primo id restituito da cerca_documenti, come un modello diligente."""
    ids = [
        id_ for output in chiamata.output_tool() for id_ in re.findall(r"\[([a-z0-9-]+)\]", output)
    ]
    assert ids, "cerca_documenti non ha restituito passaggi"
    return scrive(f"Secondo i documenti la risposta è questa [{ids[0]}].")
