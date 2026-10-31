"""La copia di un agente (app/core/clone.py): tutto copiato e niente condiviso, nessun
embedding nuovo, e una copia fallita a metà lascia la destinazione com'era."""

import shutil
from collections.abc import Iterator
from contextlib import contextmanager
from typing import Any

import pytest

from app.core import clone, embeddings, ingest, storage, tables, vectorstore
from app.core.errors import AgenteNonVuoto, ContenutoNonValido, NonTrovato
from tests.conftest import messaggi, scrivi_sessione

COPIA = "visitatore-1"
DOMANDA = "massimale cena trasferta Milano"
SQL = "SELECT stato, COUNT(*), ROUND(SUM(importo), 2) FROM note_spese GROUP BY stato ORDER BY 1"


def chunk(agent_id: str) -> dict[str, tuple[str, dict]]:
    """Testo e metadati di ogni chunk dell'agente, per id."""
    collection = vectorstore.collection_esistente(agent_id)
    if collection is None:
        return {}
    dati = collection.get(include=["documents", "metadatas"])
    return {
        id_chunk: (testo, dict(meta))
        for id_chunk, testo, meta in zip(
            dati["ids"], dati["documents"], dati["metadatas"], strict=True
        )
    }


def vettori(agent_id: str) -> dict[str, list[float]]:
    dati = vectorstore.collection_esistente(agent_id).get(include=["embeddings"])
    return {i: v.tolist() for i, v in zip(dati["ids"], dati["embeddings"], strict=True)}


def originali(agent_id: str) -> dict[str, bytes]:
    cartella = storage.cartella_file(agent_id)
    return {p.name: p.read_bytes() for p in sorted(cartella.iterdir())} if cartella.exists() else {}


def righe(agent_id: str) -> dict[str, list]:
    return {
        t["table"]: tables.esegui_select(agent_id, f"SELECT * FROM {t['table']}", 1000)["rows"]
        for t in tables.lista_tabelle(agent_id)
    }


def stato(agent_id: str) -> tuple[Any, ...]:
    """Quello che il backend sa dei dati di un agente, per confrontarlo prima e dopo."""
    return chunk(agent_id), originali(agent_id), tables.lista_tabelle(agent_id), righe(agent_id)


def test_copia_completa_con_gli_stessi_id_e_la_ricerca_che_funziona(valtesa, monkeypatch):
    # Pagine da 5 chunk, così la copia ne fa diverse anche con due documenti.
    copia_vera = vectorstore.copia
    monkeypatch.setattr(vectorstore, "copia", lambda da, a: copia_vera(da, a, passo=5))

    def nessun_embedding(testi: list[str]) -> list[list[float]]:
        raise AssertionError("la copia non deve calcolare embedding")

    embedding_veri = embeddings.embed
    monkeypatch.setattr(embeddings, "embed", nessun_embedding)
    risposta = clone.clona_agente(COPIA, valtesa)
    monkeypatch.setattr(embeddings, "embed", embedding_veri)

    assert risposta == {"agent_id": COPIA, "from": valtesa, "documents": 2, "tables": 1}
    assert len(chunk(valtesa)) > 5
    assert chunk(COPIA) == chunk(valtesa)  # stessi id, testi e metadati
    # Gli stessi vettori, a meno dell'ultima cifra di un float32: Chroma li rinormalizza per
    # la metrica coseno a ogni inserimento (lo fa anche con quelli calcolati all'upload).
    copiati, sorgente = vettori(COPIA), vettori(valtesa)
    assert copiati.keys() == sorgente.keys()
    for id_chunk, vettore in sorgente.items():
        assert copiati[id_chunk] == pytest.approx(vettore, abs=1e-6)
    assert originali(COPIA) == originali(valtesa)
    assert ingest.lista_documenti(COPIA) == ingest.lista_documenti(valtesa)
    assert tables.lista_tabelle(COPIA) == tables.lista_tabelle(valtesa)  # metadati compresi
    assert tables.esegui_select(COPIA, SQL, 10) == tables.esegui_select(valtesa, SQL, 10)
    assert vectorstore.collection_esistente(COPIA).metadata == vectorstore.METADATA_COLLECTION
    trovati = vectorstore.cerca(COPIA, DOMANDA, 4)
    attesi = vectorstore.cerca(valtesa, DOMANDA, 4)
    assert [c["id"] for c in trovati] == [c["id"] for c in attesi]
    assert [c["score"] for c in trovati] == pytest.approx([c["score"] for c in attesi])


def test_le_modifiche_alla_copia_non_toccano_la_sorgente(valtesa):
    clone.clona_agente(COPIA, valtesa)
    prima = stato(valtesa)

    ingest.indicizza_documento(COPIA, "nuovo.md", b"Un documento che ha solo la copia.")
    ingest.cancella_documento(COPIA, "faq-portale-dipendenti")
    tables.importa_tabella(COPIA, "note_spese.csv", b"id,importo,stato\n1,2.5,approvata\n")
    assert stato(valtesa) == prima
    tables.cancella_tabella(COPIA, "note_spese")
    assert stato(valtesa) == prima
    storage.cancella_agente(COPIA)

    assert stato(valtesa) == prima
    assert stato(COPIA) == ({}, {}, [], {})


def test_le_conversazioni_non_si_copiano_e_quelle_della_destinazione_restano(valtesa):
    scrivi_sessione(f"{valtesa}:s1", "Domanda alla sorgente")
    # Solo conversazioni, niente documenti né tabelle: per la copia l'agente è vuoto.
    scrivi_sessione(f"{COPIA}:s2", "Domanda alla copia")

    clone.clona_agente(COPIA, valtesa)

    assert messaggi(f"{COPIA}:s1") == []
    assert messaggi(f"{COPIA}:s2") == [{"role": "user", "content": "Domanda alla copia"}]
    assert messaggi(f"{valtesa}:s1") == [{"role": "user", "content": "Domanda alla sorgente"}]


def test_sorgente_senza_documenti_ne_tabelle_404():
    with pytest.raises(NonTrovato, match="non ha documenti né tabelle"):
        clone.clona_agente(COPIA, "mai-visto")
    # Svuotato a mano: la collection vuota e tables.db senza tabelle restano, ma dati non ce n'è.
    ingest.indicizza_documento("svuotato", "faq.md", b"Domande frequenti.")
    ingest.cancella_documento("svuotato", "faq")
    tables.importa_tabella("svuotato", "prestiti.csv", b"id\n1\n")
    tables.cancella_tabella("svuotato", "prestiti")
    with pytest.raises(NonTrovato):
        clone.clona_agente(COPIA, "svuotato")
    assert not storage.cartella_agente(COPIA).exists()


@pytest.mark.parametrize("dato", ["documento", "tabella"])
def test_destinazione_con_dati_409_e_nulla_cambia(valtesa, dato):
    if dato == "documento":
        ingest.indicizza_documento(COPIA, "mio.md", b"Un documento mio.")
    else:
        tables.importa_tabella(COPIA, "mia.csv", b"x\n1\n")
    prima = stato(COPIA)

    with pytest.raises(AgenteNonVuoto, match="già documenti o tabelle"):
        clone.clona_agente(COPIA, valtesa)

    assert stato(COPIA) == prima


def test_una_destinazione_svuotata_si_puo_riempire(valtesa):
    ingest.indicizza_documento(COPIA, "mio.md", b"Un documento mio.")
    ingest.cancella_documento(COPIA, "mio")
    tables.importa_tabella(COPIA, "mia.csv", b"x\n1\n")
    tables.cancella_tabella(COPIA, "mia")

    assert clone.clona_agente(COPIA, valtesa)["documents"] == 2
    assert chunk(COPIA) == chunk(valtesa)


@pytest.mark.parametrize(
    ("destinazione", "sorgente"),
    [("valtesa", "valtesa"), ("Copia", "valtesa"), (COPIA, "../valtesa"), (COPIA, "")],
)
def test_stesso_agente_o_id_non_validi(valtesa, destinazione, sorgente):
    with pytest.raises(ContenutoNonValido):
        clone.clona_agente(destinazione, sorgente)


def test_i_blocchi_si_prendono_sempre_nello_stesso_ordine(valtesa, monkeypatch):
    presi: list[str] = []
    blocco_vero = storage.blocco_agente

    @contextmanager
    def registra(agent_id: str) -> Iterator[None]:
        presi.append(agent_id)
        with blocco_vero(agent_id):
            yield

    monkeypatch.setattr(storage, "blocco_agente", registra)
    clone.clona_agente("zeta", valtesa)
    clone.clona_agente("alfa", valtesa)

    # In ordine alfabetico, qualunque sia la direzione: due copie incrociate non si bloccano.
    assert presi == ["valtesa", "zeta", "alfa", "valtesa"]


def vettori_a_meta(sorgente, destinazione, passo=None) -> None:
    """Copia la prima pagina di vettori e poi si guasta."""
    pagina = sorgente.get(limit=5, include=["embeddings", "documents", "metadatas"])
    destinazione.upsert(
        ids=pagina["ids"],
        embeddings=pagina["embeddings"],
        documents=pagina["documents"],
        metadatas=pagina["metadatas"],
    )
    raise OSError("disco pieno")


def guasto_al_secondo_file(copia_vera):
    copiati: list[str] = []

    def copia(origine, arrivo):
        if copiati:
            raise OSError("disco pieno")
        copiati.append(copia_vera(origine, arrivo))

    return copia


def guasto(*_: object) -> None:
    raise OSError("disco pieno")


@pytest.mark.parametrize("fase", ["vettori", "originali", "metadati delle tabelle"])
def test_una_copia_fallita_a_meta_lascia_vuota_la_destinazione(valtesa, monkeypatch, fase):
    scrivi_sessione(f"{COPIA}:s1", "Ciao")
    prima = stato(valtesa)

    with monkeypatch.context() as patch:
        if fase == "vettori":
            patch.setattr(vectorstore, "copia", vettori_a_meta)
        elif fase == "originali":
            patch.setattr(shutil, "copy2", guasto_al_secondo_file(shutil.copy2))
        else:  # tables.db è già copiato quando si guasta la scrittura di tables.json
            patch.setattr(tables, "_scrivi_meta", guasto)
        with pytest.raises(OSError, match="disco pieno"):
            clone.clona_agente(COPIA, valtesa)

    assert vectorstore.collection_esistente(COPIA) is None
    assert not storage.cartella_agente(COPIA).exists()
    assert stato(COPIA) == ({}, {}, [], {})
    assert messaggi(f"{COPIA}:s1") == [{"role": "user", "content": "Ciao"}]  # le sue chat restano
    assert stato(valtesa) == prima
    # Niente resta a metà: la copia si può rifare.
    assert clone.clona_agente(COPIA, valtesa)["tables"] == 1


def test_una_copia_fallita_non_tocca_il_file_di_un_upload_in_attesa(valtesa, monkeypatch):
    # Un upload scrive il suo file provvisorio prima di prendere il blocco dell'agente.
    provvisorio = storage.cartella_file(COPIA) / ".caricamento-abc.md"
    provvisorio.parent.mkdir(parents=True)
    provvisorio.write_bytes(b"In arrivo.")
    monkeypatch.setattr(tables, "_scrivi_meta", guasto)

    with pytest.raises(OSError):
        clone.clona_agente(COPIA, valtesa)

    assert [p.name for p in provvisorio.parent.iterdir()] == [provvisorio.name]
    assert ingest.lista_documenti(COPIA) == []
