"""Errori di dominio del core.

Il core non conosce FastAPI: solleva queste eccezioni e l'API le traduce in risposte HTTP
con corpo `{"detail": str(errore)}` (docs/api.md). Il messaggio è già in italiano e
mostrabile all'utente, quindi non deve contenere percorsi o dettagli interni.

| Eccezione                | HTTP | Quando                                                        |
|--------------------------|------|---------------------------------------------------------------|
| `NonTrovato`             | 404  | documento, tabella o (per /sql) tabelle dell'agente mancanti; |
|                          |      | per /clone, agente da copiare senza dati; per un upload,      |
|                          |      | agente cancellato mentre il file si indicizzava               |
| `LimiteRaggiunto`        | 409  | troppi documenti o tabelle per l'agente                       |
| `AgenteNonVuoto`         | 409  | per /clone, agente di destinazione che ha già dei dati        |
| `FileTroppoGrande`       | 413  | file oltre MAX_UPLOAD_MB, o troppo grande una volta letto:    |
|                          |      | zip che si gonfia, documento oltre MAX_CHUNKS_PER_DOCUMENT    |
|                          |      | chunk, tabella oltre MAX_CELLE celle                          |
| `FileNonAmmesso`         | 415  | estensione non ammessa o contenuto diverso dall'estensione    |
| `ContenutoNonValido`     | 422  | file illeggibile o vuoto, id non valido, query SQL rifiutata  |
| `ProviderNonDisponibile` | 502  | OpenAI non risponde in tempo o rifiuta la chiamata            |
| `ModelloNonDisponibile`  | 502  | chiave OpenAI mancante o rifiutata (messaggio generico)       |

Tutte derivano da `ErroreStapply`, così all'API basta un solo exception handler.
"""


class ErroreStapply(Exception):
    """Base degli errori di dominio: `str(errore)` è il messaggio per l'utente."""


class NonTrovato(ErroreStapply):
    """404: la risorsa chiesta non esiste."""


class LimiteRaggiunto(ErroreStapply):
    """409: l'agente ha già il numero massimo di documenti o di tabelle."""


class AgenteNonVuoto(ErroreStapply):
    """409: la copia di un agente andrebbe su uno che ha già documenti o tabelle."""


class FileTroppoGrande(ErroreStapply):
    """413: il file supera il limite di dimensione."""


class FileNonAmmesso(ErroreStapply):
    """415: estensione non ammessa, o contenuto che non corrisponde all'estensione."""


class ContenutoNonValido(ErroreStapply):
    """422: dati non utilizzabili (file senza testo, tabella malformata, SQL rifiutato…)."""


class ProviderNonDisponibile(ErroreStapply):
    """502: il provider del modello (OpenAI) non risponde o rifiuta la chiamata."""


class ModelloNonDisponibile(ProviderNonDisponibile):
    """502 per la chiave OpenAI mancante o rifiutata: un problema di configurazione, che chi
    usa la chat non può risolvere né deve vedere. Il messaggio è sempre lo stesso; la causa
    vera va nel log, a livello ERROR."""

    MESSAGGIO = (
        "Il modello non è disponibile in questo momento: riprova più tardi. Se il problema "
        "continua, avvisa chi gestisce stAPPly."
    )

    def __init__(self) -> None:
        super().__init__(self.MESSAGGIO)
