"""La copia di un agente su un altro: serve alla sandbox demo, dove ogni visitatore riceve la
sua copia degli agenti demo e può cambiarla senza toccare quella degli altri.

Si copiano i vettori già calcolati (nessun embedding nuovo, quindi niente costi) con gli
stessi id di chunk, così le citazioni restano identiche; poi gli originali dei documenti,
tables.db e i metadati delle tabelle. Le conversazioni no: la copia parte senza chat.
"""

import logging
from contextlib import suppress
from typing import Any

from app.core import ids, ingest, storage, tables, vectorstore
from app.core.errors import AgenteNonVuoto, ContenutoNonValido, NonTrovato

logger = logging.getLogger(__name__)


def clona_agente(destinazione: str, sorgente: str) -> dict[str, Any]:
    """Copia in `destinazione` documenti e tabelle di `sorgente`. Se qualcosa fallisce a
    metà, la destinazione torna vuota com'era.

    Restituisce le chiavi di docs/contract/agents.clone.201.json. Solleva ContenutoNonValido
    (id non valido, o lo stesso agente), NonTrovato (`sorgente` senza documenti né tabelle) o
    AgenteNonVuoto (`destinazione` ha già documenti o tabelle: la copia non sovrascrive).
    """
    ids.controlla_agent_id(destinazione)
    ids.controlla_agent_id(sorgente)
    # Prima dei blocchi: sono rientranti, e lo stesso agente passerebbe senza errori.
    if destinazione == sorgente:
        raise ContenutoNonValido("Un agente non si può copiare su sé stesso.")

    # Sempre nello stesso ordine: due copie incrociate (a da b, b da a) non si bloccano.
    primo, secondo = sorted((destinazione, sorgente))
    with storage.blocco_agente(primo), storage.blocco_agente(secondo):
        if not (ingest.ha_documenti(sorgente) or tables.ha_tabelle(sorgente)):
            raise NonTrovato("L'agente da copiare non ha documenti né tabelle.")
        if ingest.ha_documenti(destinazione) or tables.ha_tabelle(destinazione):
            raise AgenteNonVuoto(
                "L'agente di destinazione ha già documenti o tabelle: la copia non li sovrascrive."
            )
        try:
            documenti = ingest.copia_documenti(sorgente, destinazione)
            tabelle = tables.copia_tabelle(sorgente, destinazione)
        except BaseException:
            _svuota(destinazione)
            raise

    logger.info(
        "%s: copia di %s (documenti: %d, tabelle: %d)", destinazione, sorgente, documenti, tabelle
    )
    return {"agent_id": destinazione, "from": sorgente, "documents": documenti, "tables": tabelle}


def _svuota(agent_id: str) -> None:
    """Toglie quello che una copia fallita ha lasciato in un agente che non aveva documenti
    né tabelle. Non usa storage.cancella_agente: le conversazioni dell'agente restano, e
    restano anche i file provvisori di un upload in attesa del blocco (`.caricamento-…`)."""
    vectorstore.cancella_collection(agent_id)
    cartella_file = storage.cartella_file(agent_id)
    if cartella_file.exists():
        for originale in cartella_file.glob("[!.]*"):
            originale.unlink()
    storage.percorso_tabelle(agent_id).unlink(missing_ok=True)
    storage.percorso_meta_tabelle(agent_id).unlink(missing_ok=True)
    # Le cartelle se restano vuote, così un agente nuovo torna a non avere tracce su disco.
    for cartella in (cartella_file, storage.cartella_agente(agent_id)):
        with suppress(OSError):
            cartella.rmdir()
