"""La collection ChromaDB di ogni agente: aikit/vectorstore.py del corso.

Rispetto al corso: il percorso viene da DATA_DIR, un solo client per percorso e per processo,
una collection `agent_<agent_id>` per agente (isolamento semplice e cancellazione pulita),
cancellazione dei chunk di un documento, copia di una collection e telemetria spenta.
"""

from collections.abc import Sequence
from functools import lru_cache
from typing import Any

import chromadb
from chromadb.api import ClientAPI
from chromadb.api.models.Collection import Collection
from chromadb.config import Settings as ChromaSettings
from chromadb.errors import NotFoundError

from app.core import embeddings, ids, storage

METADATA_COLLECTION = {"hnsw:space": "cosine"}  # la metrica del corso (Modulo 2, Lezioni 7-9)


@lru_cache
def _client(percorso: str) -> ClientAPI:
    # Uno per percorso: Chroma rifiuta un secondo client sullo stesso percorso con altre
    # impostazioni. Le Collection invece non si mettono in cache: dopo una cancellazione
    # resterebbero in mano oggetti non più validi.
    return chromadb.PersistentClient(
        path=percorso, settings=ChromaSettings(anonymized_telemetry=False)
    )


def client() -> ClientAPI:
    return _client(str(storage.cartella_chroma().resolve()))


def apri_collection(agent_id: str) -> Collection:
    """La collection dell'agente: la crea la prima volta, poi la riapre."""
    # embedding_function=None: i vettori li calcoliamo sempre noi, mai l'embedder di Chroma.
    return client().get_or_create_collection(
        ids.nome_collection(agent_id), metadata=METADATA_COLLECTION, embedding_function=None
    )


def collection_esistente(agent_id: str) -> Collection | None:
    """La collection dell'agente se esiste, senza crearla: le letture non lasciano tracce."""
    try:
        return client().get_collection(ids.nome_collection(agent_id), embedding_function=None)
    except NotFoundError:
        return None


def indicizza(
    collection: Collection,
    ids_chunk: Sequence[str],
    testi: Sequence[str],
    vettori: Sequence[Sequence[float]],
    metadatas: Sequence[dict[str, Any]],
) -> None:
    """Upsert con embedding ESPLICITI, a lotti entro il massimo che Chroma accetta."""
    passo = client().get_max_batch_size()
    for inizio in range(0, len(ids_chunk), passo):
        fine = inizio + passo
        collection.upsert(
            ids=list(ids_chunk[inizio:fine]),
            documents=list(testi[inizio:fine]),
            embeddings=[list(v) for v in vettori[inizio:fine]],
            metadatas=list(metadatas[inizio:fine]),
        )


def copia(sorgente: Collection, destinazione: Collection, passo: int | None = None) -> None:
    """Copia tutti i chunk di `sorgente` in `destinazione`, a pagine di `passo`: stessi id,
    testi, metadati e vettori già calcolati, quindi nessun embedding nuovo."""
    passo = passo or client().get_max_batch_size()
    inizio = 0
    while True:
        pagina = sorgente.get(
            limit=passo, offset=inizio, include=["embeddings", "documents", "metadatas"]
        )
        if not pagina["ids"]:
            return
        destinazione.upsert(
            ids=pagina["ids"],
            embeddings=pagina["embeddings"],
            documents=pagina["documents"],
            metadatas=pagina["metadatas"],
        )
        inizio += passo


def search(collection: Collection, query: str, k: int) -> list[dict[str, Any]]:
    """I k chunk più simili alla query, dal più simile in giù.

    Ogni risultato è un dict {"id", "testo", "score", ...metadata}: lo score è 1 - distanza
    coseno, come nel corso. L'"id" (es. "regolamento-ferie-permessi-003") è la fonte da citare.
    """
    if collection.count() == 0:
        return []  # niente da cercare: inutile pagare l'embedding della domanda
    vettore = embeddings.embed([query])[0]
    ris = collection.query(query_embeddings=[vettore], n_results=k)
    documenti = ris["documents"] or [[]]
    distanze = ris["distances"] or [[]]
    metadatas = ris["metadatas"] or [[]]
    risultati = []
    for i, id_chunk in enumerate(ris["ids"][0]):
        risultato: dict[str, Any] = {
            "id": id_chunk,
            "testo": documenti[0][i],
            "score": 1 - distanze[0][i],
        }
        risultato.update(metadatas[0][i] or {})
        risultati.append(risultato)
    return risultati


def cerca(agent_id: str, query: str, k: int) -> list[dict[str, Any]]:
    """`search` sulla collection dell'agente; lista vuota se l'agente non ha documenti."""
    collection = collection_esistente(agent_id)
    return search(collection, query, k) if collection is not None else []


def cancella_documento(collection: Collection, document_id: str) -> None:
    """Toglie tutti i chunk di un documento (anche di una versione precedente più lunga)."""
    collection.delete(where={"document_id": document_id})


def cancella_collection(agent_id: str) -> None:
    """Cancella la collection dell'agente. Nessun errore se non c'era."""
    try:
        client().delete_collection(ids.nome_collection(agent_id))
    except NotFoundError:
        pass
