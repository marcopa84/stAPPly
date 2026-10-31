import io

import pytest
from openpyxl import Workbook

from app.core import firme
from app.core.errors import FileNonAmmesso, FileTroppoGrande
from tests.conftest import DOCUMENTI_SEED, SEED, contratto, zip_con


def xlsx_vero() -> bytes:
    libro = Workbook()
    libro.active.append(["id", "nome"])
    buffer = io.BytesIO()
    libro.save(buffer)
    return buffer.getvalue()


@pytest.mark.parametrize(
    ("filename", "atteso"),
    [("faq.PDF", ".pdf"), ("pagina.htm", ".html"), ("Pagina.HTML", ".html"), ("senza", "")],
)
def test_estensione(filename, atteso):
    assert firme.estensione(filename) == atteso


@pytest.mark.parametrize("percorso", DOCUMENTI_SEED, ids=lambda p: p.name)
def test_i_documenti_del_seed_passano(percorso):
    assert firme.controlla_documento(percorso.name, percorso.read_bytes()) == percorso.suffix


@pytest.mark.parametrize("percorso", sorted(SEED.glob("*/tabelle/*.csv")))
def test_le_tabelle_del_seed_passano(percorso):
    assert firme.controlla_tabella(percorso.name, percorso.read_bytes()) == ".csv"


def test_un_pdf_che_e_testo_viene_rifiutato_con_il_messaggio_del_contratto():
    with pytest.raises(FileNonAmmesso) as errore:
        firme.controlla_documento("regolamento.pdf", b"Non sono un PDF, sono testo.")
    assert str(errore.value) == contratto("error.json")["detail"]


def test_docx_finto_con_la_parte_principale_passa():
    contenuto = zip_con(**{"word/document.xml": b"<w:document/>", "[Content_Types].xml": b""})
    assert firme.controlla_documento("nota.docx", contenuto) == ".docx"


@pytest.mark.parametrize(
    "contenuto",
    [
        b"PK\x03\x04 zip troncato",
        b"testo qualsiasi",
        zip_con(**{"altro.xml": b"<x/>"}),
        xlsx_vero(),  # uno zip Office, ma di Excel
    ],
)
def test_docx_non_validi(contenuto):
    with pytest.raises(FileNonAmmesso, match="documento Word"):
        firme.controlla_documento("nota.docx", contenuto)


def test_xlsx_vero_passa_e_un_docx_rinominato_no():
    assert firme.controlla_tabella("dati.xlsx", xlsx_vero()) == ".xlsx"
    docx = (next(SEED.glob("*/documenti/*.docx"))).read_bytes()
    with pytest.raises(FileNonAmmesso, match="Excel"):
        firme.controlla_tabella("dati.xlsx", docx)


def test_zip_bomb(imposta):
    imposta(max_upload_mb=1)  # decompresso al massimo 10 MB
    bomba = zip_con(**{"word/document.xml": b"0" * (11 * 1024 * 1024)})
    assert len(bomba) < 100_000
    with pytest.raises(FileTroppoGrande, match="decompresso"):
        firme.controlla_documento("bomba.docx", bomba)


def test_testo_utf8_con_bom_passa():
    assert firme.controlla_documento("note.txt", "\ufeffCaffè".encode()) == ".txt"
    assert firme.controlla_tabella("dati.csv", "\ufeffa;b\n1;2\n".encode()) == ".csv"


@pytest.mark.parametrize(
    ("filename", "contenuto"),
    [
        ("note.txt", "Caffè".encode("latin-1")),
        ("note.md", b"testo\x00binario"),
        ("pagina.html", b"\xff\xfe<\x00h\x00>\x00"),  # UTF-16
        ("dati.csv", b"a,b\n\x80,1\n"),
    ],
)
def test_testo_non_utf8_o_binario_viene_rifiutato(filename, contenuto):
    with pytest.raises(FileNonAmmesso, match="UTF-8"):
        if filename.endswith(".csv"):
            firme.controlla_tabella(filename, contenuto)
        else:
            firme.controlla_documento(filename, contenuto)


@pytest.mark.parametrize(
    "filename", ["programma.exe", "dati.csv", "foglio.xlsx", "senza_estensione"]
)
def test_estensioni_non_ammesse_per_i_documenti(filename):
    with pytest.raises(FileNonAmmesso, match="sono ammessi .txt, .md, .pdf, .docx, .html"):
        firme.controlla_documento(filename, b"qualcosa")


@pytest.mark.parametrize("filename", ["note.txt", "faq.pdf", "dati.xls"])
def test_estensioni_non_ammesse_per_le_tabelle(filename):
    with pytest.raises(FileNonAmmesso, match="sono ammessi .csv, .xlsx"):
        firme.controlla_tabella(filename, b"qualcosa")


def test_file_troppo_grande(imposta):
    imposta(max_upload_mb=1)
    firme.controlla_documento("ok.txt", b"a" * (1024 * 1024))
    with pytest.raises(FileTroppoGrande, match="1 MB"):
        firme.controlla_documento("troppo.txt", b"a" * (1024 * 1024 + 1))
