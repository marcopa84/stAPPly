"""Da un file caricato ai chunk nella collection dell'agente.

È il ciclo di solutions/obiettivo2_documenti.py (corso) portato a un file alla volta:
load → clean → chunk_recursive → embedding → Chroma. L'ordine conta: prima tutto quello che
può fallire (controlli, lettura, embedding), poi la sostituzione della versione precedente.
Così un upload sbagliato non cancella mai il documento che c'era.

La lista dei documenti si ricostruisce dai metadati dei chunk: Chroma è l'unica fonte, e non
c'è un indice a parte da tenere allineato.
"""

import logging
import os
import shutil
import tempfile
from pathlib import Path
from typing import Any

from chromadb.api.models.Collection import Collection

from app.config import get_settings
from app.core import embeddings, firme, ids, storage, vectorstore
from app.core.clean import clean, pipeline_for
from app.core.errors import ContenutoNonValido, LimiteRaggiunto, NonTrovato
from app.core.loaders import load

logger = logging.getLogger(__name__)


def chunk_recursive(testo: str, size: int) -> list[str]:
    """Taglia sull'ULTIMO separatore che ci sta in `size` caratteri, provando
    i separatori dal più forte al più debole (paragrafo, riga, frase); se
    nessuno ci sta, taglio secco a `size`. Poi riparte sul resto. Restituisce
    pezzi lunghi al massimo `size`."""
    # Stessi tagli di aikit/chunk.py, con un ciclo al posto della ricorsione e un indice al
    # posto del "resto": la ricorsione sfonda il limite di Python oltre ~1000 chunk, e
    # ricopiare il resto a ogni taglio rende quadratico un documento lungo.
    pezzi = []
    inizio = 0
    while len(testo) - inizio > size:
        finestra = testo[inizio : inizio + size]
        for separatore in ("\n\n", "\n", ". "):
            posizione = finestra.rfind(separatore)
            if posizione > 0:
                pezzi.append(finestra[:posizione])
                inizio += posizione + len(separatore)
                break
        else:
            pezzi.append(finestra)
            inizio += size
    pezzi.append(testo[inizio:])
    return pezzi


def indicizza_documento(agent_id: str, filename: str, contenuto: bytes) -> dict[str, Any]:
    """Indicizza un documento caricato. Uno con lo stesso document_id viene sostituito, anche
    se aveva un'altra estensione.

    Restituisce le chiavi di docs/contract/documents.upload.201.json. Solleva FileNonAmmesso,
    FileTroppoGrande, LimiteRaggiunto, ContenutoNonValido o ProviderNonDisponibile.
    """
    nome = ids.nome_file(filename)
    ext = firme.controlla_documento(nome, contenuto)
    document_id = ids.document_id(nome)
    cartella = storage.cartella_file(agent_id)
    _controlla_limite(agent_id, document_id)  # subito, prima di pagare gli embedding

    # I loader vogliono un percorso: il file aspetta in un nome provvisorio finché non è
    # indicizzato, così l'originale della versione precedente resta intatto fino alla fine.
    cartella.mkdir(parents=True, exist_ok=True)
    descrittore, nome_provvisorio = tempfile.mkstemp(
        dir=cartella, prefix=".caricamento-", suffix=ext
    )
    provvisorio = Path(nome_provvisorio)
    try:
        with os.fdopen(descrittore, "wb") as file:
            file.write(contenuto)
        pezzi = _chunk(provvisorio, nome)
        vettori = embeddings.embed(pezzi)

        with storage.blocco_agente(agent_id):
            # Di nuovo, sotto il blocco: un altro upload può essere finito nel frattempo.
            sostituito = _controlla_limite(agent_id, document_id)
            uploaded_at = storage.adesso()
            collection = vectorstore.apri_collection(agent_id)
            vectorstore.cancella_documento(collection, document_id)
            vectorstore.indicizza(
                collection,
                [ids.chunk_id(document_id, i) for i in range(len(pezzi))],
                pezzi,
                vettori,
                [
                    {
                        "source": nome,
                        "document_id": document_id,
                        "chunk": i,
                        "size_bytes": len(contenuto),
                        "uploaded_at": uploaded_at,
                    }
                    for i in range(len(pezzi))
                ],
            )
            os.replace(provvisorio, cartella / f"{document_id}{ext}")
            _cancella_originali(cartella, document_id, tranne=ext)
    finally:
        provvisorio.unlink(missing_ok=True)

    logger.info("%s: %s indicizzato in %d chunk", agent_id, nome, len(pezzi))
    return {
        "document_id": document_id,
        "filename": nome,
        "chunks": len(pezzi),
        "size_bytes": len(contenuto),
        "uploaded_at": uploaded_at,
        "replaced": sostituito,
    }


def lista_documenti(agent_id: str) -> list[dict[str, Any]]:
    """I documenti dell'agente ordinati per nome del file, con le chiavi di
    docs/contract/documents.list.200.json. Lista vuota per un agente mai visto."""
    collection = vectorstore.collection_esistente(agent_id)
    if collection is None:
        return []
    documenti = _riepilogo(collection).values()
    return sorted(documenti, key=lambda d: (d["filename"].casefold(), d["filename"]))


def ha_documenti(agent_id: str) -> bool:
    """True se l'agente ha almeno un chunk (una collection rimasta vuota non conta)."""
    collection = vectorstore.collection_esistente(agent_id)
    return collection is not None and collection.count() > 0


def copia_documenti(sorgente: str, destinazione: str) -> int:
    """Copia in `destinazione` i chunk (con i loro vettori) e gli originali dei documenti di
    `sorgente`; restituisce quanti documenti ha copiato.

    Chi la chiama tiene il blocco di tutti e due gli agenti e, se fallisce, ripulisce la
    destinazione (app/core/clone.py).
    """
    collection = vectorstore.collection_esistente(sorgente)
    documenti = _riepilogo(collection) if collection is not None else {}
    if not documenti:
        return 0
    vectorstore.copia(collection, vectorstore.apri_collection(destinazione))
    origine, arrivo = storage.cartella_file(sorgente), storage.cartella_file(destinazione)
    arrivo.mkdir(parents=True, exist_ok=True)
    for document_id in documenti:
        for ext in firme.ESTENSIONI_DOCUMENTI:
            originale = origine / f"{document_id}{ext}"
            if originale.exists():
                shutil.copy2(originale, arrivo / originale.name)
    return len(documenti)


def cancella_documento(agent_id: str, document_id: str) -> None:
    """Toglie chunk e file originale di un documento. NonTrovato se non c'è."""
    non_trovato = NonTrovato(f"Il documento «{document_id}» non esiste.")
    # Un id che non può esistere si ferma qui: diventerebbe parte di un percorso.
    if not ids.document_id_valido(document_id):
        raise non_trovato
    with storage.blocco_agente(agent_id):
        collection = vectorstore.collection_esistente(agent_id)
        presente = (
            collection is not None
            and collection.get(where={"document_id": document_id}, limit=1, include=[])["ids"]
        )
        if not presente:
            raise non_trovato
        vectorstore.cancella_documento(collection, document_id)
        _cancella_originali(storage.cartella_file(agent_id), document_id)


def _chunk(percorso: Path, nome: str) -> list[str]:
    """Testo pulito e diviso in chunk, senza pezzi vuoti."""
    try:
        testo = "\n".join(pagina.text for pagina in load(str(percorso)))
        testo = clean(testo.replace("\ufeff", ""), pipeline_for(str(percorso)))
    except Exception as errore:
        # pypdf, python-docx e lxml sollevano eccezioni di ogni tipo su un file rovinato.
        logger.warning("lettura di %s non riuscita: %r", nome, errore)
        raise ContenutoNonValido(
            "Il file non si riesce a leggere: potrebbe essere danneggiato o protetto da password."
        ) from errore
    pezzi = [pezzo for pezzo in chunk_recursive(testo, get_settings().chunk_size) if pezzo.strip()]
    if not pezzi:
        raise ContenutoNonValido(
            "Il file non contiene testo da indicizzare (un PDF scansionato, per esempio, "
            "contiene solo immagini)."
        )
    return pezzi


def _riepilogo(collection: Collection) -> dict[str, dict[str, Any]]:
    """Un dict per documento, ricostruito dai metadati dei suoi chunk."""
    documenti: dict[str, dict[str, Any]] = {}
    for meta in collection.get(include=["metadatas"])["metadatas"] or []:
        documento = documenti.setdefault(
            str(meta["document_id"]),
            {
                "document_id": meta["document_id"],
                "filename": meta["source"],
                "chunks": 0,
                "size_bytes": meta["size_bytes"],
                "uploaded_at": meta["uploaded_at"],
            },
        )
        documento["chunks"] += 1
    return documenti


def _controlla_limite(agent_id: str, document_id: str) -> bool:
    """True se il documento sostituisce uno già caricato; LimiteRaggiunto se è nuovo e
    l'agente ha già il numero massimo di documenti."""
    collection = vectorstore.collection_esistente(agent_id)
    presenti = _riepilogo(collection) if collection is not None else {}
    if document_id in presenti:
        return True
    massimo = get_settings().max_documents_per_agent
    if len(presenti) >= massimo:
        raise LimiteRaggiunto(
            f"Limite di {massimo} documenti per agente raggiunto: cancellane uno, oppure "
            "carica un file con lo stesso nome per sostituirlo."
        )
    return False


def _cancella_originali(cartella: Path, document_id: str, tranne: str | None = None) -> None:
    """Toglie l'originale del documento, con qualunque estensione ammessa abbia."""
    for ext in firme.ESTENSIONI_DOCUMENTI:
        if ext != tranne:
            (cartella / f"{document_id}{ext}").unlink(missing_ok=True)
