import io
import re

import docx
import pytest

from app.core import embeddings, ingest, storage, vectorstore
from app.core.errors import (
    ContenutoNonValido,
    FileNonAmmesso,
    FileTroppoGrande,
    LimiteRaggiunto,
    NonTrovato,
    ProviderNonDisponibile,
)
from tests.conftest import DOCUMENTI_SEED, SEED, contratto, embed_finto, forma

AGENTE = "demo-test"
ISO_UTC = re.compile(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ")


def paragrafi(parola: str, quanti: int) -> bytes:
    """Un testo da `quanti` paragrafi di qualche centinaio di caratteri sulla stessa parola."""
    paragrafo = f"Paragrafo su {parola}. " + " ".join([parola] * 40)
    return "\n\n".join(f"{i}. {paragrafo}" for i in range(quanti)).encode()


def file_dell_agente(agent_id: str = AGENTE) -> list[str]:
    cartella = storage.cartella_file(agent_id)
    return sorted(p.name for p in cartella.iterdir()) if cartella.exists() else []


def chunk_dell_agente(agent_id: str = AGENTE) -> list[str]:
    """I testi dei chunk dell'agente, in ordine di id."""
    chunk = vectorstore.apri_collection(agent_id).get()
    return [testo for _, testo in sorted(zip(chunk["ids"], chunk["documents"], strict=True))]


def word(*blocchi: str | list[list[str]]) -> bytes:
    """Un .docx in memoria: una stringa è un paragrafo, una lista di righe è una tabella."""
    documento = docx.Document()
    for blocco in blocchi:
        if isinstance(blocco, str):
            documento.add_paragraph(blocco)
            continue
        tabella = documento.add_table(rows=len(blocco), cols=len(blocco[0]))
        for riga, valori in zip(tabella.rows, blocco, strict=True):
            for cella, valore in zip(riga.cells, valori, strict=True):
                cella.text = valore
    buffer = io.BytesIO()
    documento.save(buffer)
    return buffer.getvalue()


def vietato(testi: list[str]) -> list[list[float]]:
    raise AssertionError("embedding non necessario")


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


def test_due_upload_insieme_non_superano_il_limite(imposta, monkeypatch):
    imposta(max_documents_per_agent=1)

    def embed_con_corsa(testi: list[str]) -> list[list[float]]:
        # Un altro upload finisce mentre questo calcola gli embedding: il limite va
        # ricontrollato sotto il blocco, non basta quello fatto all'inizio.
        monkeypatch.setattr(embeddings, "embed", embed_finto)
        ingest.indicizza_documento(AGENTE, "altro.md", b"Un altro documento.")
        return embed_finto(testi)

    monkeypatch.setattr(embeddings, "embed", embed_con_corsa)

    with pytest.raises(LimiteRaggiunto):
        ingest.indicizza_documento(AGENTE, "primo.md", b"Il primo documento.")
    assert [d["document_id"] for d in ingest.lista_documenti(AGENTE)] == ["altro"]
    assert file_dell_agente() == ["altro.md"]  # e nessun file provvisorio rimasto


def test_agente_cancellato_durante_l_upload_non_lascia_dati(monkeypatch):
    ingest.indicizza_documento(AGENTE, "faq.md", b"Domande frequenti sul prestito.")

    def embed_e_cancella(testi: list[str]) -> list[list[float]]:
        storage.cancella_agente(AGENTE)  # la DELETE di Laravel arriva durante gli embedding
        return embed_finto(testi)

    monkeypatch.setattr(embeddings, "embed", embed_e_cancella)

    with pytest.raises(NonTrovato, match="cancellato durante il caricamento"):
        ingest.indicizza_documento(AGENTE, "orari.md", b"Orari di apertura.")
    assert vectorstore.collection_esistente(AGENTE) is None
    assert not storage.cartella_agente(AGENTE).exists()


def test_limite_documenti_salvo_sostituzione(imposta):
    imposta(max_documents_per_agent=2)
    ingest.indicizza_documento(AGENTE, "a.txt", b"Primo documento.")
    ingest.indicizza_documento(AGENTE, "b.txt", b"Secondo documento.")

    with pytest.raises(LimiteRaggiunto, match="2 documenti"):
        ingest.indicizza_documento(AGENTE, "c.txt", b"Terzo documento.")
    assert ingest.indicizza_documento(AGENTE, "A.md", b"Primo, rivisto.")["replaced"] is True
    assert file_dell_agente() == ["a.md", "b.txt"]


@pytest.mark.parametrize(
    ("filename", "contenuto"),
    [
        ("lungo.md", paragrafi("ferie", 10)),  # oltre 3 × 200 caratteri: nemmeno si spezza
        ("corti.txt", "\n\n".join(f"{i}. " + "x" * 98 for i in range(5)).encode()),
        ("gonfio.docx", word(*[" ".join(["permessi"] * 40)] * 20)),  # pochi KB compressi
    ],
    ids=["testo-lungo", "troppi-chunk", "docx"],
)
def test_documento_troppo_lungo_413_prima_degli_embedding(
    imposta, monkeypatch, filename, contenuto
):
    imposta(max_chunks_per_document=3, chunk_size=200)
    monkeypatch.setattr(embeddings, "embed", vietato)

    with pytest.raises(FileTroppoGrande, match="al massimo 3 passaggi da 200 caratteri"):
        ingest.indicizza_documento(AGENTE, filename, contenuto)
    assert ingest.lista_documenti(AGENTE) == []
    assert file_dell_agente() == []


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


def pagina_pdf(numero: int, corpo: str) -> str:
    """Una pagina come la estrae pypdf: due righe di testata, il corpo, il numero di pagina."""
    return f"Biblioteca civica — Regolamento dei prestiti\nUfficio prestiti\n{corpo}\n{numero}"


def test_pdf_senza_testate_e_numeri_di_pagina_ma_con_tutte_le_celle():
    tabella = "Tessera\nOrdinaria\n5\nStudenti\n8"  # pypdf estrae una cella per riga
    pagine = [
        pagina_pdf(n, f"Art. {n}\nTesto dell'art. {n}.\n{tabella}\nFine dell'art. {n}.")
        for n in (1, 2, 3)
    ]

    testo = ingest.pulisci(pagine, ".pdf")

    assert "Biblioteca civica" not in testo
    assert "Ufficio prestiti" not in testo
    assert testo.count(tabella) == 3  # celle ripetute e numeri restano
    assert testo.endswith("\nFine dell'art. 3.")  # il numero di pagina no


def test_pdf_tiene_le_celle_di_sole_cifre_sul_bordo_della_pagina():
    # In fondo alla pagina 2 una cella «1» sta sopra il numero di pagina.
    pagine = [pagina_pdf(1, "Testo."), pagina_pdf(2, "Riviste\n1"), pagina_pdf(3, "Altro.")]
    assert ingest.pulisci(pagine, ".pdf") == "Testo.\nRiviste\n1\nAltro."
    # Senza numeri di pagina, ogni pagina finisce con un importo: non cresce con la pagina.
    importi = ["Spese della trasferta.\nCena\n120", "Albergo\n45", "Varie\nTreno\nTaxi\n300"]
    assert ingest.pulisci(importi, ".pdf") == "\n".join(importi)


def test_le_tabelle_del_pdf_del_seed_restano_intere():
    percorso = SEED / "valdoria" / "documenti" / "regolamento-prestiti.pdf"
    ingest.indicizza_documento(AGENTE, percorso.name, percorso.read_bytes())

    chunk = chunk_dell_agente()
    # Art. 5: prestiti contemporanei per tessera; art. 7: una cella in fondo alla pagina 2.
    assert any("Ordinaria\n5\nStudenti\n8\nFamiglia\n10" in testo for testo in chunk)
    assert any("Riviste\n1\n14 giorni" in testo for testo in chunk)
    assert not any("approvato con delibera n. 14/2025" in testo for testo in chunk)  # testata


def test_html_con_una_colonna_e_una_sezione_note_resta_intero():
    contenuto = (
        "<h1>Circolare rimborsi</h1>"
        "<table><tr><th>Voce</th><th>Massimale</th><th>Note</th></tr>"
        "<tr><td>Cena</td><td>40 euro</td><td>con ricevuta</td></tr></table>"
        "<h2>Note</h2><p>Le spese oltre 500 euro richiedono il visto del direttore.</p>"
        "<h2>Approvazione</h2><p>In vigore dal 1° luglio.</p>"
    ).encode()

    ingest.indicizza_documento(AGENTE, "circolare.html", contenuto)

    assert chunk_dell_agente() == [
        "Circolare rimborsi\nVoce\nMassimale\nNote\nCena\n40 euro\ncon ricevuta\nNote\n"
        "Le spese oltre 500 euro richiedono il visto del direttore.\nApprovazione\n"
        "In vigore dal 1° luglio."
    ]


@pytest.mark.parametrize(
    "testo",
    [
        "Checklist di ingresso.\n*** END OF CHECKLIST ***\nAppendice: oltre 500 euro, visto.",
        "Premessa: vale per tutte le sedi.\n*** START\n1. Si timbra all'ingresso.\n2. E all'uscita",
    ],
    ids=["end", "start"],
)
def test_txt_con_i_marcatori_di_gutenberg_resta_intero(testo):
    ingest.indicizza_documento(AGENTE, "procedura.txt", testo.encode())
    assert chunk_dell_agente() == [testo]  # e le righe di un elenco restano righe


def test_docx_con_una_tabella():
    contenuto = word(
        "Massimali di spesa per le trasferte",
        [["Voce", "Massimale"], ["Cena a Milano", "45 euro"], ["Hotel", "130 euro a notte"]],
        "Per importi superiori serve l'autorizzazione.",
    )

    ingest.indicizza_documento(AGENTE, "massimali.docx", contenuto)

    assert chunk_dell_agente() == [
        "Massimali di spesa per le trasferte\nVoce | Massimale\nCena a Milano | 45 euro\n"
        "Hotel | 130 euro a notte\nPer importi superiori serve l'autorizzazione."
    ]


def test_docx_fatto_solo_di_una_tabella_con_celle_unite():
    documento = docx.Document()
    tabella = documento.add_table(rows=2, cols=3)
    tabella.cell(0, 0).merge(tabella.cell(0, 2)).text = "Orari della sede Centrale"
    for cella, testo in zip(tabella.rows[1].cells, ("Lunedì", "9-13", "15-19"), strict=True):
        cella.text = testo
    buffer = io.BytesIO()
    documento.save(buffer)

    ingest.indicizza_documento(AGENTE, "orari.docx", buffer.getvalue())

    assert chunk_dell_agente() == ["Orari della sede Centrale\nLunedì | 9-13 | 15-19"]


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
