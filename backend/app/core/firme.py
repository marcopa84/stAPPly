"""Controllo dei file caricati: estensione, dimensione e contenuto (la "firma").

L'estensione la sceglie chi carica il file, il contenuto no: un `.pdf` che in realtà è un
eseguibile o un `.docx` che si decomprime in gigabyte vanno fermati prima che li apra una
libreria di parsing.
"""

import io
import zipfile
from pathlib import PurePosixPath

from app.config import get_settings
from app.core.errors import FileNonAmmesso, FileTroppoGrande

ESTENSIONI_DOCUMENTI = (".txt", ".md", ".pdf", ".docx", ".html")
ESTENSIONI_TABELLE = (".csv", ".xlsx")
_SINONIMI = {".htm": ".html"}

# Un .docx o un .xlsx è uno zip di XML: il testo si comprime molto, ma oltre questo multiplo
# del limite di upload è più probabile una zip bomb che un documento vero.
FATTORE_DECOMPRESSIONE = 10
MAX_VOCI_ZIP = 10_000

_PARTE_PRINCIPALE = {".docx": "word/document.xml", ".xlsx": "xl/workbook.xml"}
_DESCRIZIONI = {
    ".pdf": "un PDF valido",
    ".docx": "un documento Word (.docx) valido",
    ".xlsx": "un foglio Excel (.xlsx) valido",
    ".txt": "un file di testo valido",
    ".md": "un file Markdown valido",
    ".html": "una pagina HTML valida",
    ".csv": "un file CSV valido",
}


def estensione(filename: str) -> str:
    """L'estensione in minuscolo, con `.htm` ricondotto a `.html`."""
    ext = PurePosixPath(filename.replace("\\", "/")).suffix.lower()
    return _SINONIMI.get(ext, ext)


def controlla_documento(filename: str, contenuto: bytes) -> str:
    """Controlla un documento da indicizzare e ne restituisce l'estensione normalizzata.

    Solleva FileNonAmmesso (415) o FileTroppoGrande (413).
    """
    return _controlla(filename, contenuto, ESTENSIONI_DOCUMENTI, "i documenti")


def controlla_tabella(filename: str, contenuto: bytes) -> str:
    """Come `controlla_documento`, per i file di tabella (.csv, .xlsx)."""
    return _controlla(filename, contenuto, ESTENSIONI_TABELLE, "le tabelle")


def _controlla(filename: str, contenuto: bytes, ammesse: tuple[str, ...], per: str) -> str:
    ext = estensione(filename)
    if ext not in ammesse:
        formato = f"Formato {ext} non ammesso" if ext else "Il file non ha un'estensione"
        raise FileNonAmmesso(f"{formato}: per {per} sono ammessi {', '.join(ammesse)}.")

    settings = get_settings()
    if len(contenuto) > settings.max_upload_bytes:
        raise FileTroppoGrande(f"Il file supera il limite di {settings.max_upload_mb} MB.")

    if ext == ".pdf":
        valido = contenuto.startswith(b"%PDF-")
    elif ext in _PARTE_PRINCIPALE:
        massimo = FATTORE_DECOMPRESSIONE * settings.max_upload_bytes
        valido = _zip_office(contenuto, _PARTE_PRINCIPALE[ext], massimo)
    else:
        if not _testo_utf8(contenuto):
            raise FileNonAmmesso(f"Il file non è {_DESCRIZIONI[ext]}: serve testo in UTF-8.")
        valido = True

    if not valido:
        raise FileNonAmmesso(
            f"Il file non è {_DESCRIZIONI[ext]}: il contenuto non corrisponde all'estensione."
        )
    return ext


def _zip_office(contenuto: bytes, parte_principale: str, massimo: int) -> bool:
    """True se è uno zip con la parte principale del formato Office; FileTroppoGrande se
    decompresso supererebbe `massimo` byte."""
    try:
        with zipfile.ZipFile(io.BytesIO(contenuto)) as archivio:
            voci = archivio.infolist()
    except (zipfile.BadZipFile, OSError, ValueError, EOFError):
        return False
    if parte_principale not in {voce.filename for voce in voci}:
        return False
    # Le dimensioni dichiarate bastano: zipfile non decomprime mai oltre quelle.
    if len(voci) > MAX_VOCI_ZIP or sum(voce.file_size for voce in voci) > massimo:
        raise FileTroppoGrande(
            f"Il file, una volta decompresso, supera il limite di {massimo // (1024 * 1024)} MB."
        )
    return True


def _testo_utf8(contenuto: bytes) -> bool:
    """Testo UTF-8 valido (BOM ammesso) e senza byte NUL, che tradiscono un file binario."""
    if b"\x00" in contenuto:
        return False
    try:
        contenuto.decode("utf-8-sig")
    except UnicodeDecodeError:
        return False
    return True
