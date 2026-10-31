import re

import pytest

from app.core import embeddings, ingest, storage, vectorstore
from app.core.errors import (
    ContenutoNonValido,
    FileNonAmmesso,
    LimiteRaggiunto,
    NonTrovato,
    ProviderNonDisponibile,
)
from tests.conftest import DOCUMENTI_SEED, contratto, forma

AGENTE = "demo-test"
ISO_UTC = re.compile(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ")


def paragrafi(parola: str, quanti: int) -> bytes:
    """Un testo da `quanti` paragrafi di qualche centinaio di caratteri sulla stessa parola."""
    paragrafo = f"Paragrafo su {parola}. " + " ".join([parola] * 40)
    return "\n\n".join(f"{i}. {paragrafo}" for i in range(quanti)).encode()


def file_dell_agente(agent_id: str = AGENTE) -> list[str]:
    cartella = storage.cartella_file(agent_id)
    return sorted(p.name for p in cartella.iterdir()) if cartella.exists() else []


@pytest.mark.parametrize("percorso", DOCUMENTI_SEED, ids=lambda p: p.name)
def test_indicizza_ogni_formato_del_seed(percorso):
    risposta = ingest.indicizza_documento(AGENTE, percorso.name, percorso.read_bytes())

    assert forma(risposta) == forma(contratto("documents.upload.201.json"))
    assert risposta["document_id"] == percorso.stem
    assert risposta["filename"] == percorso.name
    assert risposta["size_bytes"] == percorso.stat().st_size
    assert risposta["chunks"] > 0
    assert risposta["replaced"] is False
    assert ISO_UTC.fullmatch(risposta["uploaded_at"])
    assert file_dell_agente() == [percorso.name]

    # Una ricerca con il testo di un chunk ritrova proprio quel chunk, con i suoi metadati.
    collection = vectorstore.apri_collection(AGENTE)
    primo = collection.get(ids=[f"{percorso.stem}-000"])["documents"][0]
    trovato = vectorstore.search(collection, primo, 3)[0]
    assert trovato["id"] == f"{percorso.stem}-000"
    assert trovato["testo"] == primo
    assert trovato["source"] == percorso.name
    assert trovato["document_id"] == percorso.stem
    assert trovato["chunk"] == 0
    assert trovato["size_bytes"] == risposta["size_bytes"]
    assert trovato["uploaded_at"] == risposta["uploaded_at"]
    assert trovato["score"] == pytest.approx(1.0)


def test_indicizza_un_txt_con_bom():
    contenuto = "\ufeffCircolare interna.\n\nIl buono pasto vale 8 euro dal 1° luglio.".encode()
    risposta = ingest.indicizza_documento(AGENTE, "Circolare Buoni Pasto.txt", contenuto)

    assert risposta["document_id"] == "circolare-buoni-pasto"
    assert risposta["chunks"] == 1
    trovato = vectorstore.cerca(AGENTE, "buono pasto euro", 1)[0]
    assert trovato["id"] == "circolare-buoni-pasto-000"
    assert trovato["testo"].startswith("Circolare interna.")  # niente BOM nel testo


def test_i_chunk_rispettano_chunk_size_e_sono_numerati(imposta):
    imposta(chunk_size=200)
    risposta = ingest.indicizza_documento(AGENTE, "lungo.md", paragrafi("ferie", 10))

    collection = vectorstore.apri_collection(AGENTE)
    chunk = collection.get()
    assert len(chunk["ids"]) == risposta["chunks"] > 10
    assert sorted(chunk["ids"]) == [f"lungo-{i:03d}" for i in range(risposta["chunks"])]
    assert all(0 < len(testo) <= 200 for testo in chunk["documents"])


def test_sostituzione_con_lo_stesso_document_id_anche_con_altra_estensione(imposta):
    imposta(chunk_size=200)
    prima = ingest.indicizza_documento(AGENTE, "regolamento.txt", paragrafi("ferie", 6))
    dopo = ingest.indicizza_documento(AGENTE, "Regolamento.md", b"Nuovo regolamento: permessi.")

    assert prima["chunks"] > 1
    assert dopo["replaced"] is True
    assert dopo["document_id"] == prima["document_id"] == "regolamento"
    assert dopo["chunks"] == 1
    # Nessun chunk della versione lunga rimasto indietro, e un solo originale su disco.
    assert vectorstore.apri_collection(AGENTE).get()["ids"] == ["regolamento-000"]
    assert file_dell_agente() == ["regolamento.md"]
    assert [d["filename"] for d in ingest.lista_documenti(AGENTE)] == ["Regolamento.md"]


@pytest.mark.parametrize(
    ("filename", "contenuto", "errore"),
    [
        ("regolamento.pdf", b"%PDF-1.4 e poi niente", ContenutoNonValido),
        ("regolamento.txt", b"", ContenutoNonValido),
        ("regolamento.md", b"Nuovo regolamento.", ProviderNonDisponibile),
    ],
)
def test_una_sostituzione_fallita_lascia_la_versione_precedente(
    monkeypatch, filename, contenuto, errore
):
    originale = next(p for p in DOCUMENTI_SEED if p.suffix == ".pdf").read_bytes()
    prima = ingest.indicizza_documento(AGENTE, "regolamento.pdf", originale)
    if errore is ProviderNonDisponibile:

        def provider_giu(testi):
            raise ProviderNonDisponibile("OpenAI non risponde.")

        monkeypatch.setattr(embeddings, "embed", provider_giu)

    with pytest.raises(errore):
        ingest.indicizza_documento(AGENTE, filename, contenuto)

    assert ingest.lista_documenti(AGENTE) == [{k: v for k, v in prima.items() if k != "replaced"}]
    assert file_dell_agente() == ["regolamento.pdf"]  # e nessun file provvisorio rimasto
    assert (storage.cartella_file(AGENTE) / "regolamento.pdf").read_bytes() == originale


def test_limite_documenti_salvo_sostituzione(imposta):
    imposta(max_documents_per_agent=2)
    ingest.indicizza_documento(AGENTE, "a.txt", b"Primo documento.")
    ingest.indicizza_documento(AGENTE, "b.txt", b"Secondo documento.")

    with pytest.raises(LimiteRaggiunto, match="2 documenti"):
        ingest.indicizza_documento(AGENTE, "c.txt", b"Terzo documento.")
    assert ingest.indicizza_documento(AGENTE, "A.md", b"Primo, rivisto.")["replaced"] is True
    assert file_dell_agente() == ["a.md", "b.txt"]


def test_formato_non_ammesso_non_lascia_tracce():
    with pytest.raises(FileNonAmmesso):
        ingest.indicizza_documento(AGENTE, "dati.csv", b"a,b\n1,2\n")
    assert ingest.lista_documenti(AGENTE) == []
    assert file_dell_agente() == []


def test_file_senza_testo():
    with pytest.raises(ContenutoNonValido, match="non contiene testo"):
        ingest.indicizza_documento(AGENTE, "vuoto.txt", b"  \n\n  ")
    assert file_dell_agente() == []


def test_nome_file_con_percorso_resta_nella_cartella_dell_agente():
    risposta = ingest.indicizza_documento(AGENTE, "../../../evil.txt", b"Nessuna evasione.")
    assert risposta["filename"] == "evil.txt"
    assert file_dell_agente() == ["evil.txt"]


def test_htm_e_trattato_come_html():
    contenuto = b"<html><body><nav>Menu</nav><p>Orari della biblioteca.</p></body></html>"
    risposta = ingest.indicizza_documento(AGENTE, "Orari.HTM", contenuto)
    assert risposta["document_id"] == "orari"
    assert file_dell_agente() == ["orari.html"]
    assert vectorstore.cerca(AGENTE, "orari", 1)[0]["testo"] == "Orari della biblioteca."


def test_lista_documenti_ordinata_per_filename_con_le_chiavi_del_contratto(imposta):
    imposta(chunk_size=200)
    caricati = [
        ingest.indicizza_documento(AGENTE, "b.md", b"Secondo."),
        ingest.indicizza_documento(AGENTE, "A.txt", paragrafi("trasferte", 3)),
        ingest.indicizza_documento(AGENTE, "c.html", b"<p>Terzo.</p>"),
    ]

    documenti = ingest.lista_documenti(AGENTE)
    assert forma(documenti) == forma(contratto("documents.list.200.json")["documents"])
    assert [d["filename"] for d in documenti] == ["A.txt", "b.md", "c.html"]
    # La lista, ricostruita dai metadati dei chunk, dice le stesse cose degli upload.
    senza_replaced = [{k: v for k, v in c.items() if k != "replaced"} for c in caricati]
    assert documenti == [senza_replaced[1], senza_replaced[0], senza_replaced[2]]
    assert documenti[0]["chunks"] > 1


def test_lista_di_un_agente_mai_visto_e_vuota_e_non_crea_nulla():
    assert ingest.lista_documenti("mai-visto") == []
    assert vectorstore.collection_esistente("mai-visto") is None
    assert vectorstore.cerca("mai-visto", "qualcosa", 4) == []


def test_cancella_documento():
    ingest.indicizza_documento(AGENTE, "faq.md", b"Domande frequenti sul prestito.")
    ingest.indicizza_documento(AGENTE, "orari.txt", b"Orari di apertura.")

    ingest.cancella_documento(AGENTE, "faq")

    assert [d["document_id"] for d in ingest.lista_documenti(AGENTE)] == ["orari"]
    assert file_dell_agente() == ["orari.txt"]
    assert all(r["document_id"] == "orari" for r in vectorstore.cerca(AGENTE, "prestito", 4))
    with pytest.raises(NonTrovato):
        ingest.cancella_documento(AGENTE, "faq")


@pytest.mark.parametrize("document_id", ["mai-caricato", "../orari", "orari.txt", ""])
def test_cancella_documento_inesistente(document_id):
    ingest.indicizza_documento(AGENTE, "orari.txt", b"Orari di apertura.")
    with pytest.raises(NonTrovato):
        ingest.cancella_documento(AGENTE, document_id)
    assert file_dell_agente() == ["orari.txt"]


def test_cancella_documento_di_un_agente_mai_visto():
    with pytest.raises(NonTrovato):
        ingest.cancella_documento("mai-visto", "faq")


def test_isolamento_tra_agenti():
    ingest.indicizza_documento("agente-a", "ferie.txt", b"Ferie: ventisei giorni l'anno.")
    ingest.indicizza_documento("agente-b", "prestiti.txt", b"Prestiti: trenta giorni, rinnovabili.")

    # Anche una domanda che parla solo dei documenti di B, fatta ad A, trova solo chunk di A.
    risultati = vectorstore.cerca("agente-a", "prestiti trenta giorni rinnovabili", 10)
    assert [r["id"] for r in risultati] == ["ferie-000"]
    assert [d["document_id"] for d in ingest.lista_documenti("agente-b")] == ["prestiti"]
    assert file_dell_agente("agente-a") == ["ferie.txt"]


def test_search_su_collection_vuota_non_calcola_embedding(monkeypatch):
    def vietato(testi):
        raise AssertionError("embedding non necessario")

    monkeypatch.setattr(embeddings, "embed", vietato)
    assert vectorstore.search(vectorstore.apri_collection(AGENTE), "ferie", 4) == []


def test_chunk_recursive_come_nel_corso():
    assert ingest.chunk_recursive("corto", 500) == ["corto"]
    assert ingest.chunk_recursive("", 500) == [""]
    # Taglia sull'ultimo separatore più forte che sta nella finestra...
    assert ingest.chunk_recursive("aaaa\n\nbbbb\ncccc", 12) == ["aaaa", "bbbb\ncccc"]
    assert ingest.chunk_recursive("aaaa. bbbb. cccc", 12) == ["aaaa. bbbb", "cccc"]
    # ...e se non ce n'è nessuno taglia secco.
    assert ingest.chunk_recursive("x" * 1200, 500) == ["x" * 500, "x" * 500, "x" * 200]


def test_chunk_recursive_regge_documenti_lunghi():
    testo = "\n\n".join(f"Paragrafo numero {i}." for i in range(50_000))
    pezzi = ingest.chunk_recursive(testo, 500)  # da ricorsiva sarebbe RecursionError
    assert len(pezzi) > 2000
    assert all(len(p) <= 500 for p in pezzi)
    assert "\n\n".join(pezzi) == testo  # tagliando sui paragrafi non si perde nulla
