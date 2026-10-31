"""La griglia di prova del criterio 5 (CONSEGNA.md del corso) su un agente demo.

Uso (da backend/):  uv run python -m scripts.griglia valtesa [--lanci 2]

Legge le domande da docs/griglia/<tema>.json, le fa all'agente con la funzione del core che
usa anche l'API (`chat.rispondi`) e scrive docs/griglia/risultati-<tema>-<data>.md: per ogni
lancio risposta, strada, tool con gli argomenti, citazioni e tempo, accanto alla risposta vera
e con lo spazio per segnare l'esito a mano.

Ogni domanda si lancia `--lanci` volte, ogni volta in una sessione nuova con un nome nuovo:
nella stessa sessione il modello vedrebbe la sua risposta di prima. Il follow-up va nella
sessione della domanda a cui si riferisce, subito dopo di lei.

Le istruzioni sono quelle che Laravel passa alla chat, lette dalla loro fonte unica: le
costanti di frontend/database/seeders/DemoSeeder.php. Un file passato a parte (--istruzioni)
potrebbe restare indietro rispetto al seeder senza che nessuno se ne accorga; il parser qui
sotto invece legge il testo vero, o si ferma con un errore se il formato cambia. Per provare
una cura si cambia la costante nel seeder, si rilancia la griglia e, se funziona, si riallinea
Laravel con `php artisan db:seed --class=DemoSeeder`.

Servono OPENAI_API_KEY e gli agenti demo già indicizzati (scripts/seed_demo.py): senza, lo
script esce prima di chiamare il modello.
"""

import argparse
import asyncio
import json
import re
import sys
import time
from collections.abc import Sequence
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any

from agents import Model

from app.config import BACKEND_DIR, get_settings
from app.core import chat, ingest, tables
from app.core.errors import ErroreStapply

GRIGLIE = BACKEND_DIR.parent / "docs" / "griglia"
DEMO_SEEDER = BACKEND_DIR.parent / "frontend" / "database" / "seeders" / "DemoSeeder.php"

TIPI = {"documenti", "dati", "documenti+dati", "senza risposta", "follow-up"}
CHIAVI_GRIGLIA = {"agente", "nome_agente", "costante_istruzioni", "domande"}
CHIAVI_DOMANDA = {"n", "tipo", "domanda", "risposta_vera", "strada_attesa", "fonti"}
# Una fonte è un passaggio di un documento oppure una SELECT scritta a mano con le sue righe.
CHIAVI_FONTE = ({"file", "chunk", "passaggio"}, {"sql", "risultato"})
ESITI = "giusta, sbagliata, «non trovato», rifiuto giusto"

# `const NOME = <<<'MARCA'`, il corpo, poi la marca di chiusura, preceduta dal suo rientro.
_NOWDOC = r"const {nome} = <<<'(?P<marca>\w+)'\n(?P<corpo>.*?)\n(?P<rientro>[ \t]*)(?P=marca)\b"


class GrigliaNonValida(Exception):
    """La griglia, il seeder o i dati dell'agente non permettono di lanciarla."""


@dataclass
class Lancio:
    """Un lancio di una domanda: la risposta del core, oppure l'errore che l'ha fermato."""

    n: int
    lancio: int
    sessione: str
    secondi: float
    risposta: chat.RispostaChat | None = None
    errore: str | None = None


def carica_griglia(percorso: Path) -> dict[str, Any]:
    """La griglia, controllata quanto serve a lanciarla: chiavi, tipi, numeri unici, fonti
    leggibili e follow-up che seguono una domanda vera. La composizione (tre domande sui
    documenti, tre sui dati…) la controllano i test sulle due griglie demo."""
    try:
        griglia = json.loads(percorso.read_text(encoding="utf-8"))
    except ValueError as errore:
        raise GrigliaNonValida(f"{percorso.name} non è JSON valido: {errore}") from errore
    if mancano := CHIAVI_GRIGLIA - griglia.keys():
        raise GrigliaNonValida(f"{percorso.name}: mancano {', '.join(sorted(mancano))}.")
    per_numero: dict[int, dict[str, Any]] = {}
    for domanda in griglia["domande"]:
        dove = f"{percorso.name}, domanda {domanda.get('n', '?')}"
        if mancano := CHIAVI_DOMANDA - domanda.keys():
            raise GrigliaNonValida(f"{dove}: mancano {', '.join(sorted(mancano))}.")
        if domanda["tipo"] not in TIPI:
            raise GrigliaNonValida(f"{dove}: tipo «{domanda['tipo']}» sconosciuto.")
        if domanda["n"] in per_numero:
            raise GrigliaNonValida(f"{dove}: numero già usato.")
        if not all(any(c <= fonte.keys() for c in CHIAVI_FONTE) for fonte in domanda["fonti"]):
            raise GrigliaNonValida(f"{dove}: una fonte non è né un passaggio né una SELECT.")
        per_numero[domanda["n"]] = domanda
    for domanda in per_numero.values():
        if domanda["tipo"] == "follow-up":
            seguita = per_numero.get(domanda.get("segue"))
            if seguita is None or seguita["tipo"] == "follow-up":
                raise GrigliaNonValida(
                    f"{percorso.name}, domanda {domanda['n']}: il follow-up deve seguire "
                    "una domanda della griglia («segue»)."
                )
    return griglia


def leggi_istruzioni(costante: str, seeder: Path = DEMO_SEEDER) -> str:
    """Il testo di una costante nowdoc di DemoSeeder.php come lo vede PHP (dalla 7.3): il
    rientro della riga di chiusura si toglie da ogni riga. Se il formato non è quello
    atteso solleva GrigliaNonValida: mai un testo diverso da quello che salva Laravel."""
    testo = seeder.read_text(encoding="utf-8")
    trovato = re.search(_NOWDOC.format(nome=re.escape(costante)), testo, re.DOTALL)
    if trovato is None:
        raise GrigliaNonValida(f"In {seeder.name} non trovo «const {costante} = <<<'…'».")
    rientro = trovato["rientro"]
    righe = []
    for riga in trovato["corpo"].split("\n"):
        if riga.startswith(rientro):
            righe.append(riga.removeprefix(rientro))
        elif not riga.strip():
            righe.append("")  # PHP accetta le righe vuote anche senza rientro
        else:
            # PHP stesso rifiuterebbe il file: meglio fermarsi che indovinare.
            raise GrigliaNonValida(
                f"In {seeder.name} la costante {costante} ha una riga meno rientrata della "
                "marca di chiusura."
            )
    return "\n".join(righe)


def controlla_agente(agent_id: str) -> None:
    """L'agente deve avere documenti e tabelle: senza, la griglia pagherebbe il modello per
    risposte che non dicono niente."""
    mancano = []
    if not ingest.lista_documenti(agent_id):
        mancano.append("documenti")
    if not tables.ha_tabelle(agent_id):
        mancano.append("tabelle")
    if mancano:
        raise GrigliaNonValida(
            f"L'agente {agent_id} non ha {' né '.join(mancano)} in {get_settings().data_dir}: "
            "prima prepara gli agenti demo con `uv run python -m scripts.seed_demo`."
        )


async def lancia(
    griglia: dict[str, Any],
    istruzioni: str,
    lanci: int,
    prefisso: str,
    *,
    modello: Model | None = None,
) -> list[Lancio]:
    """Ogni domanda `lanci` volte, ogni volta nella sessione nuova `<prefisso>-d<n>-l<lancio>`;
    i follow-up subito dopo la loro domanda, nella stessa sessione. Un errore di dominio (il
    modello che non risponde, per esempio) resta nel risultato di quel lancio, e si va avanti.

    `modello` serve ai test, come in `chat.rispondi`.
    """
    domande = griglia["domande"]
    catene = [
        [domanda, *(f for f in domande if f["tipo"] == "follow-up" and f["segue"] == domanda["n"])]
        for domanda in domande
        if domanda["tipo"] != "follow-up"
    ]
    risultati = []
    for numero in range(1, lanci + 1):
        for catena in catene:
            sessione = f"{prefisso}-d{catena[0]['n']}-l{numero}"
            for domanda in catena:
                risultato = await _chiedi(griglia, domanda, numero, sessione, istruzioni, modello)
                print(
                    f"lancio {numero}, domanda {domanda['n']}: "
                    f"{_strada(risultato)}, {_secondi(risultato)}"
                )
                risultati.append(risultato)
    return risultati


async def _chiedi(
    griglia: dict[str, Any],
    domanda: dict[str, Any],
    numero: int,
    sessione: str,
    istruzioni: str,
    modello: Model | None,
) -> Lancio:
    inizio = time.perf_counter()
    try:
        risposta = await chat.rispondi(
            griglia["agente"],
            sessione,
            domanda["domanda"],
            istruzioni,
            griglia["nome_agente"],
            modello=modello,
        )
    except ErroreStapply as errore:
        secondi = time.perf_counter() - inizio
        return Lancio(domanda["n"], numero, sessione, secondi, errore=str(errore))
    return Lancio(domanda["n"], numero, sessione, time.perf_counter() - inizio, risposta)


def risultati_md(
    griglia: dict[str, Any],
    tema: str,
    istruzioni: str,
    lanci: int,
    risultati: Sequence[Lancio],
    quando: datetime,
) -> str:
    """Il file dei risultati: la tabella da compilare, poi ogni domanda con i suoi lanci."""
    settings = get_settings()
    domande = griglia["domande"]
    per_domanda = {d["n"]: [r for r in risultati if r.n == d["n"]] for d in domande}
    righe = [
        f"# Griglia di prova: {griglia['nome_agente']} ({griglia['agente']})",
        "",
        f"Lanciata il {quando:%d/%m/%Y alle %H:%M} con "
        f"`uv run python -m scripts.griglia {tema} --lanci {lanci}`: modello {settings.model}, "
        f"K {settings.top_k}. Ogni lancio in una sessione nuova, il follow-up nella sessione "
        f"della sua domanda. Domande e risposte vere in [{tema}.json]({tema}.json).",
        "",
        f"Da compilare: sotto ogni lancio l'esito ({ESITI}); poi, nella tabella, quante volte "
        f"la domanda è giusta (per esempio «1/{lanci}»).",
        "",
        "| # | Tipo | Domanda | Risposta vera | Strada attesa | Strade prese | Esito |",
        "|---|---|---|---|---|---|---|",
    ]
    for d in domande:
        strade = ", ".join(_strada(r) for r in per_domanda[d["n"]])
        righe.append(
            f"| {d['n']} | {_tipo(d)} | {_cella(d['domanda'])} | {_cella(d['risposta_vera'])} "
            f"| {d['strada_attesa']} | {strade} | …/{lanci} |"
        )
    righe.append(f"| | | | | | **totale** | **…/{lanci * len(domande)}** |")

    for d in domande:
        righe += [
            "",
            f"## {d['n']}. {_tipo(d)}",
            "",
            f"**Domanda:** {d['domanda']}",
            "",
            f"**Risposta vera:** {d['risposta_vera']}",
            "",
            "**Come è stata trovata:**",
            "",
            *(f"- {_fonte(fonte)}" for fonte in d["fonti"]),
            *(f"- «{p}» non compare in nessun documento né tabella" for p in d.get("assenti", [])),
        ]
        if "nota" in d:
            righe += ["", f"*Nota:* {d['nota']}"]
        for risultato in per_domanda[d["n"]]:
            righe += ["", *_lancio_md(risultato)]

    righe += [
        "",
        "## Istruzioni",
        "",
        f"`DemoSeeder::{griglia['costante_istruzioni']}`, come le passa Laravel; il backend "
        "aggiunge le sue regole fisse.",
        "",
        "```text",
        istruzioni,
        "```",
        "",
    ]
    return "\n".join(righe)


def _lancio_md(risultato: Lancio) -> list[str]:
    testa = (
        f"### Lancio {risultato.lancio} · sessione `{risultato.sessione}` · {_secondi(risultato)}"
    )
    risposta = risultato.risposta
    if risposta is None:
        return [testa, "", f"**Errore:** {risultato.errore}", "", "**Esito:** …"]
    citazioni = ", ".join(f"`{s['id']}` ({s['filename']})" for s in risposta.sources)
    sintesi = f"**Strada:** {risposta.route} · **Citazioni:** {citazioni or 'nessuna'}"
    if risposta.status != "ok":
        sintesi += f" · **Stato:** {risposta.status}"
    righe = [testa, "", sintesi, ""]
    if risposta.tools:
        chiamate = (
            f"{t['name']} {json.dumps(t['arguments'], ensure_ascii=False)}" for t in risposta.tools
        )
        righe += ["```text", *chiamate, "```", ""]
    righe += [f"> {riga}".rstrip() for riga in risposta.answer.splitlines()]
    return [*righe, "", "**Esito:** …"]


def _fonte(fonte: dict[str, Any]) -> str:
    if "sql" in fonte:
        return f"`{fonte['sql']}` → `{json.dumps(fonte['risultato'], ensure_ascii=False)}`"
    return f"`{fonte['file']}`, chunk `{fonte['chunk']}`: «{fonte['passaggio']}»"


def _tipo(domanda: dict[str, Any]) -> str:
    if domanda["tipo"] == "follow-up":
        return f"follow-up della {domanda['segue']}"
    return domanda["tipo"]


def _strada(risultato: Lancio) -> str:
    return risultato.risposta.route if risultato.risposta else "errore"


def _secondi(risultato: Lancio) -> str:
    return f"{risultato.secondi:.1f} s".replace(".", ",")


def _cella(testo: str) -> str:
    """Un testo dentro una cella di tabella markdown: niente `|` né a capo."""
    return " ".join(testo.split()).replace("|", "\\|")


def main(argv: Sequence[str] | None = None, *, modello: Model | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="python -m scripts.griglia",
        description="Lancia la griglia di prova su un agente demo e ne scrive i risultati.",
    )
    parser.add_argument(
        "tema",
        choices=sorted(p.stem for p in GRIGLIE.glob("*.json")),
        help="la griglia da lanciare: docs/griglia/<tema>.json",
    )
    parser.add_argument(
        "--lanci", type=int, default=2, help="lanci per domanda, ognuno in una sessione nuova"
    )
    parser.add_argument(
        "--uscita", type=Path, default=GRIGLIE, help="cartella del file dei risultati"
    )
    args = parser.parse_args(argv)
    if args.lanci < 1:
        parser.error("--lanci deve essere almeno 1")

    if not get_settings().openai_api_key:
        print(
            "Manca OPENAI_API_KEY (in backend/.env): serve al modello che risponde. "
            "Nessuna domanda è stata lanciata.",
            file=sys.stderr,
        )
        return 1
    try:
        griglia = carica_griglia(GRIGLIE / f"{args.tema}.json")
        istruzioni = leggi_istruzioni(griglia["costante_istruzioni"])
        controlla_agente(griglia["agente"])
    except (GrigliaNonValida, ErroreStapply, OSError) as errore:
        print(f"Errore: {errore}", file=sys.stderr)
        return 1

    quando = datetime.now()
    prefisso = f"griglia-{quando:%Y%m%d-%H%M%S}"
    risultati = asyncio.run(lancia(griglia, istruzioni, args.lanci, prefisso, modello=modello))
    percorso = args.uscita / f"risultati-{args.tema}-{quando:%Y%m%d-%H%M%S}.md"
    percorso.parent.mkdir(parents=True, exist_ok=True)
    testo = risultati_md(griglia, args.tema, istruzioni, args.lanci, risultati, quando)
    percorso.write_text(testo, encoding="utf-8")
    print(f"Risultati in {percorso}: segna l'esito accanto a ogni risposta.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
