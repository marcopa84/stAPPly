"""DOCX → testo dai paragrafi e dalle tabelle dell'XML (python-docx). Di solito un unico Document.

Un .docx è un archivio zip di XML: il testo c'è, ma annegato nei tag. `python-docx`
ce lo espone come paragrafi e tabelle, nell'ordine del documento.

Nota: `import docx` qui prende il pacchetto installato **python-docx** (import
assoluto). Questo file, per Python, è `loaders.docx` — non c'è collisione.
"""

import docx
from docx.table import Table

from .base import Document


def load_docx(path: str) -> list[Document]:
    documento = docx.Document(path)
    righe: list[str] = []
    # Paragrafi e tabelle nell'ordine del documento: `documento.paragraphs` da solo salterebbe
    # le tabelle, dove regolamenti e policy tengono spesso massimali, scadenze e importi.
    for blocco in documento.iter_inner_content():
        if isinstance(blocco, Table):
            righe.extend(_righe_tabella(blocco))
        elif blocco.text:
            righe.append(blocco.text)
    return [Document("\n".join(righe), {"source": path, "type": "docx"})]


def _righe_tabella(tabella: Table) -> list[str]:
    """Una riga di testo per riga della tabella, con le celle separate da « | ».

    python-docx ripete una cella unita in ogni colonna che copre: si tiene una volta sola.
    """
    righe = []
    for riga in tabella.rows:
        celle: list[str] = []
        precedente = None
        for cella in riga.cells:
            if cella._tc is not precedente:
                celle.append(" ".join(cella.text.split()))
            precedente = cella._tc
        if any(celle):
            righe.append(" | ".join(celle))
    return righe
