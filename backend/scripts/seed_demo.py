"""Prepara gli agenti demo: indicizza seed/valtesa e seed/valdoria come demo-valtesa e
demo-valdoria, con le stesse funzioni del core che usa l'API.

Uso (da backend/):  uv run python -m scripts.seed_demo

Idempotente: prima cancella tutto quello che il backend sa dei due agenti (documenti, tabelle
e anche le loro conversazioni), poi ricarica i file. Gli embedding richiedono OPENAI_API_KEY:
senza, lo script esce prima di toccare qualunque dato.
"""

import sys
from pathlib import Path

from app.config import BACKEND_DIR, get_settings
from app.core import ingest, storage, tables
from app.core.errors import ErroreStapply

SEED = BACKEND_DIR / "seed"
AGENTI_DEMO = {"demo-valtesa": SEED / "valtesa", "demo-valdoria": SEED / "valdoria"}


def file_in(cartella: Path) -> list[Path]:
    """I file di una cartella del seed, in ordine, senza quelli nascosti (.DS_Store)."""
    return sorted(p for p in cartella.iterdir() if p.is_file() and not p.name.startswith("."))


def prepara(agent_id: str, cartella: Path) -> None:
    storage.cancella_agente(agent_id)
    print(f"{agent_id} (da {cartella.relative_to(BACKEND_DIR)})")
    for percorso in file_in(cartella / "documenti"):
        documento = ingest.indicizza_documento(agent_id, percorso.name, percorso.read_bytes())
        print(f"  documento {documento['filename']}: {documento['chunks']} chunk")
    for percorso in file_in(cartella / "tabelle"):
        tabella = tables.importa_tabella(agent_id, percorso.name, percorso.read_bytes())
        colonne = len(tabella["columns"])
        print(f"  tabella {tabella['table']}: {tabella['rows']} righe, {colonne} colonne")


def main() -> int:
    settings = get_settings()
    if not settings.openai_api_key:
        print(
            "Manca OPENAI_API_KEY (in backend/.env): serve a calcolare gli embedding dei "
            "documenti. Nessun dato è stato toccato.",
            file=sys.stderr,
        )
        return 1
    print(f"Dati in {settings.data_dir}")
    try:
        for agent_id, cartella in AGENTI_DEMO.items():
            prepara(agent_id, cartella)
    except ErroreStapply as errore:
        print(f"Errore: {errore}\nRilancia lo script quando è risolto.", file=sys.stderr)
        return 1
    print("Agenti demo pronti.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
