"""Dalle eccezioni alle risposte HTTP, con il corpo `{"detail": ...}` di docs/api.md."""

from http import HTTPStatus

from fastapi import FastAPI, Request
from fastapi.exception_handlers import http_exception_handler
from fastapi.responses import JSONResponse, Response
from starlette.exceptions import HTTPException as StarletteHTTPException

from app.core.errors import (
    AgenteNonVuoto,
    ContenutoNonValido,
    ErroreStapply,
    FileNonAmmesso,
    FileTroppoGrande,
    LimiteRaggiunto,
    NonTrovato,
    ProviderNonDisponibile,
)

# La tabella di docs/api.md (e di app/core/errors.py).
STATI: dict[type[ErroreStapply], int] = {
    NonTrovato: 404,
    LimiteRaggiunto: 409,
    AgenteNonVuoto: 409,
    FileTroppoGrande: 413,
    FileNonAmmesso: 415,
    ContenutoNonValido: 422,
    ProviderNonDisponibile: 502,
}

MESSAGGIO_ERRORE_INTERNO = "Errore interno del backend: riprova tra poco."

# Gli errori che solleva Starlette stesso, con la frase inglese dello standard come detail.
MESSAGGI_HTTP = {
    404: "Percorso inesistente: controlla l'indirizzo della richiesta.",
    405: "Metodo non ammesso per questo percorso.",
}


async def errore_di_dominio(request: Request, errore: Exception) -> JSONResponse:
    stato = next((s for tipo, s in STATI.items() if isinstance(errore, tipo)), 500)
    return JSONResponse({"detail": str(errore)}, status_code=stato)


async def errore_http(request: Request, errore: StarletteHTTPException) -> Response:
    """Il gestore di FastAPI, con il detail in italiano per una rotta che non esiste (404) o un
    metodo che non ha (405). I detail scritti da noi, come quello del 401, restano com'erano;
    gli header pure (il 405 porta `Allow`)."""
    stato = errore.status_code
    if stato in MESSAGGI_HTTP and errore.detail == HTTPStatus(stato).phrase:
        errore = StarletteHTTPException(stato, MESSAGGI_HTTP[stato], errore.headers)
    return await http_exception_handler(request, errore)


async def errore_imprevisto(request: Request, errore: Exception) -> JSONResponse:
    # Il traceback finisce nel log di uvicorn (Starlette rilancia l'eccezione dopo questa
    # risposta), mai nella risposta: il client vede solo un messaggio generico.
    return JSONResponse({"detail": MESSAGGIO_ERRORE_INTERNO}, status_code=500)


def registra_gestori(app: FastAPI) -> None:
    app.add_exception_handler(ErroreStapply, errore_di_dominio)
    app.add_exception_handler(StarletteHTTPException, errore_http)
    app.add_exception_handler(Exception, errore_imprevisto)
