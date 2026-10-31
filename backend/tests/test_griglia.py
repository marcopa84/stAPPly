"""scripts/griglia.py e le griglie di docs/griglia/: composizione, risposte vere ricontrollate
sul seed, sessioni dei lanci, istruzioni lette da DemoSeeder e file dei risultati.

Il modello è sempre quello finto, e un fixture vieta di costruire quello vero: anche con una
chiave nell'ambiente, da qui non parte nessuna chiamata a OpenAI.
"""

import asyncio
import json
import os
import re
import shutil
import subprocess
from collections import Counter
from collections.abc import Callable
from pathlib import Path
from typing import Any

import httpx
import openai
import pytest

from app.config import get_settings
from app.core import agent, tables, vectorstore
from scripts import griglia, seed_demo
from tests.conftest import SEED
from tests.modello_finto import Chiamata, ModelloFinto, chiama, cita_il_primo_passaggio, scrive

TEMI = ("valtesa", "valdoria")
SQL = "SELECT COUNT(*) AS note FROM note_spese WHERE stato = 'in attesa'"


def carica(tema: str) -> dict[str, Any]:
    return griglia.carica_griglia(griglia.GRIGLIE / f"{tema}.json")


def spazi(testo: str) -> str:
    """Il testo con gli spazi normalizzati: dai PDF i passaggi escono spezzati su più righe."""
    return " ".join(testo.split())


def eco(chiamata: Chiamata) -> list[Any]:
    """Risponde ripetendo la domanda: così ogni risposta si riconosce nella memoria."""
    return scrive(f"Risposta a: {chiamata.messaggi()[-1][1]}")


def vietato(*args: object, **kwargs: object) -> None:
    raise AssertionError("Non doveva essere chiamato.")


@pytest.fixture(autouse=True)
def niente_modello_vero(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(agent, "crea_modello", vietato)


@pytest.fixture
def demo_valtesa() -> dict[str, Any]:
    """La griglia di Valtesa, con demo-valtesa preparato da seed_demo (embedding finti)."""
    seed_demo.prepara("demo-valtesa", SEED / "valtesa")
    return carica("valtesa")


@pytest.fixture(params=TEMI)
def tema_indicizzato(request: pytest.FixtureRequest) -> tuple[str, dict[str, Any]]:
    """Ogni tema con il suo agente demo preparato da seed_demo (embedding finti)."""
    tema = request.param
    dati = carica(tema)
    seed_demo.prepara(dati["agente"], SEED / tema)
    return tema, dati


# --- Le griglie --------------------------------------------------------------------------


@pytest.mark.parametrize("tema", TEMI)
def test_la_griglia_ha_le_otto_domande_della_consegna_piu_il_follow_up(tema):
    domande = carica(tema)["domande"]

    assert Counter(d["tipo"] for d in domande) == {
        "documenti": 3,
        "dati": 3,
        "documenti+dati": 1,
        "senza risposta": 1,
        "follow-up": 1,
    }
    assert [d["n"] for d in domande] == list(range(1, 10))
    [follow_up] = [d for d in domande if d["tipo"] == "follow-up"]
    assert domande[follow_up["segue"] - 1]["tipo"] in {"documenti", "dati", "documenti+dati"}
    for d in domande:
        if d["tipo"] in {"documenti", "dati", "documenti+dati"}:
            assert d["strada_attesa"] == d["tipo"]
        if d["tipo"] == "senza risposta":
            assert d["fonti"] == [] and d["assenti"]
        else:
            # Come è stata trovata: passaggi per i documenti, una SELECT per i dati.
            fonti = {"documenti" if "file" in f else "dati" for f in d["fonti"]}
            assert fonti == set(d["strada_attesa"].split("+")), d["n"]


def test_i_passaggi_delle_risposte_vere_sono_nei_chunk_indicizzati(tema_indicizzato):
    _, dati = tema_indicizzato
    collection = vectorstore.apri_collection(dati["agente"])
    passaggi = [f for d in dati["domande"] for f in d["fonti"] if "file" in f]

    assert passaggi
    for fonte in passaggi:
        chunk = collection.get(ids=[fonte["chunk"]])
        assert chunk["ids"] == [fonte["chunk"]], fonte
        assert chunk["metadatas"][0]["source"] == fonte["file"]
        assert spazi(fonte["passaggio"]) in spazi(chunk["documents"][0]), fonte


def test_le_select_delle_risposte_vere_danno_le_righe_scritte_nella_griglia(tema_indicizzato):
    _, dati = tema_indicizzato
    select = [f for d in dati["domande"] for f in d["fonti"] if "sql" in f]

    assert select
    for fonte in select:
        # Le tabelle importate dal backend: le stesse che interroga query_db.
        assert tables.esegui_select(dati["agente"], fonte["sql"], 100)["rows"] == fonte["risultato"]


def test_la_domanda_senza_risposta_non_ha_risposta_nel_seed(tema_indicizzato):
    tema, dati = tema_indicizzato
    chunk = vectorstore.apri_collection(dati["agente"]).get()["documents"]
    righe = [p.read_text(encoding="utf-8") for p in seed_demo.file_in(SEED / tema / "tabelle")]
    [domanda] = [d for d in dati["domande"] if d["tipo"] == "senza risposta"]

    for parola in domanda["assenti"]:
        assert not any(parola in testo.lower() for testo in [*chunk, *righe])


@pytest.mark.parametrize(
    "modifica, errore",
    [
        (lambda g: g["domande"][8].update(segue=42), "il follow-up deve seguire"),
        (lambda g: g["domande"][8].update(segue=9), "il follow-up deve seguire"),
        (lambda g: g["domande"][1].update(n=1), "numero già usato"),
        (lambda g: g["domande"][0].update(tipo="altro"), "tipo «altro» sconosciuto"),
        (lambda g: g["domande"][0]["fonti"].append({"file": "x.md"}), "né un passaggio"),
        (lambda g: g.pop("costante_istruzioni"), "mancano costante_istruzioni"),
    ],
    ids=["segue-inesistente", "segue-se-stesso", "numero-doppio", "tipo", "fonte", "chiave"],
)
def test_carica_griglia_rifiuta_una_griglia_che_non_si_puo_lanciare(
    tmp_path: Path, modifica: Callable[[dict[str, Any]], object], errore: str
):
    dati = json.loads((griglia.GRIGLIE / "valtesa.json").read_text(encoding="utf-8"))
    modifica(dati)
    percorso = tmp_path / "rotta.json"
    percorso.write_text(json.dumps(dati), encoding="utf-8")

    with pytest.raises(griglia.GrigliaNonValida, match=errore):
        griglia.carica_griglia(percorso)


# --- Le istruzioni da DemoSeeder -----------------------------------------------------------


@pytest.mark.parametrize("tema", TEMI)
def test_le_istruzioni_si_leggono_da_demo_seeder(tema):
    istruzioni = griglia.leggi_istruzioni(carica(tema)["costante_istruzioni"])
    seeder = griglia.DEMO_SEEDER.read_text(encoding="utf-8")

    assert istruzioni.strip() and not istruzioni[0].isspace()
    # Ogni riga è nel seeder così com'è, con davanti solo il rientro del codice PHP.
    for riga in filter(None, istruzioni.splitlines()):  # le righe vuote PHP le tiene vuote
        assert re.search(rf"\n[ \t]+{re.escape(riga)}\n", seeder), riga


@pytest.mark.parametrize("tema", TEMI)
def test_il_parser_legge_le_costanti_come_php(tema):
    if shutil.which("php") is None:
        # In CI PHP c'è sempre: lì un test saltato nasconderebbe una divergenza.
        if os.environ.get("CI"):
            pytest.fail("in CI serve php per confrontare il parser con PHP")
        pytest.skip("serve php")
    costante = carica(tema)["costante_istruzioni"]
    # Una classe madre finta al posto di frontend/vendor: per leggere una costante basta che
    # `extends Seeder` si risolva, e la CI del backend non deve installare il frontend.
    codice = (
        r"namespace Illuminate\Database { abstract class Seeder {} } "
        r"namespace { require $argv[1]; "
        r"echo constant('Database\Seeders\DemoSeeder::' . $argv[2]); }"
    )

    php = subprocess.run(
        ["php", "-r", codice, str(griglia.DEMO_SEEDER), costante],
        capture_output=True,
        text=True,
        check=True,
        timeout=60,
    )

    assert griglia.leggi_istruzioni(costante) == php.stdout


def test_leggi_istruzioni_toglie_il_rientro_della_marca_di_chiusura(tmp_path: Path):
    seeder = tmp_path / "DemoSeeder.php"
    seeder.write_text(
        "<?php\nclass DemoSeeder\n{\n"
        "    public const PROVA_2 = <<<'TXT'\n        Altra.\n        TXT;\n\n"
        "    public const PROVA = <<<'TXT'\n"
        "        Prima riga.\n"
        "\n"
        "          - con due spazi in più.\n"
        "        TXT;\n"
        "}\n",
        encoding="utf-8",
    )

    assert griglia.leggi_istruzioni("PROVA", seeder) == "Prima riga.\n\n  - con due spazi in più."


@pytest.mark.parametrize(
    "costante",
    [
        "    public const ALTRA = <<<'TXT'\n        Testo.\n        TXT;\n",
        "    public const PROVA = <<<'TXT'\n      Meno rientrata.\n        TXT;\n",
    ],
    ids=["costante-mancante", "riga-meno-rientrata"],
)
def test_leggi_istruzioni_si_ferma_se_il_formato_non_torna(tmp_path: Path, costante: str):
    seeder = tmp_path / "DemoSeeder.php"
    seeder.write_text(f"<?php\nclass DemoSeeder\n{{\n{costante}}}\n", encoding="utf-8")

    with pytest.raises(griglia.GrigliaNonValida):
        griglia.leggi_istruzioni("PROVA", seeder)


# --- I lanci -------------------------------------------------------------------------------


def test_ogni_lancio_in_una_sessione_nuova_e_il_follow_up_in_quella_della_sua_domanda(
    demo_valtesa,
):
    domande = demo_valtesa["domande"]
    [follow_up] = [d for d in domande if d["tipo"] == "follow-up"]
    seguita = domande[follow_up["segue"] - 1]
    modello = ModelloFinto(*[eco] * (2 * len(domande)))

    risultati = asyncio.run(
        griglia.lancia(demo_valtesa, "Istruzioni di prova.", 2, "griglia-prova", modello=modello)
    )

    assert Counter(r.n for r in risultati) == {d["n"]: 2 for d in domande}
    sessioni = {(r.n, r.lancio): r.sessione for r in risultati}
    principali = [s for (n, _), s in sessioni.items() if n != follow_up["n"]]
    assert len(set(principali)) == len(principali) == 2 * (len(domande) - 1)
    ordine = [(r.n, r.lancio) for r in risultati]
    for lancio in (1, 2):
        assert sessioni[(follow_up["n"], lancio)] == sessioni[(seguita["n"], lancio)]
        assert ordine.index((follow_up["n"], lancio)) == ordine.index((seguita["n"], lancio)) + 1
    # Al modello ogni domanda arriva senza memoria; il follow-up con lo scambio della sua.
    for chiamata in modello.chiamate:
        messaggi = chiamata.messaggi()
        if messaggi[-1][1] == follow_up["domanda"]:
            assert messaggi == [
                ("user", seguita["domanda"]),
                ("assistant", f"Risposta a: {seguita['domanda']}"),
                ("user", follow_up["domanda"]),
            ]
        else:
            assert len(messaggi) == 1


def test_un_errore_del_modello_resta_nel_suo_lancio_e_la_griglia_continua(demo_valtesa):
    domande = demo_valtesa["domande"]
    errore = openai.APIConnectionError(
        request=httpx.Request("POST", "https://api.openai.com/v1/responses")
    )
    modello = ModelloFinto(errore, *[eco] * (len(domande) - 1))

    risultati = asyncio.run(
        griglia.lancia(demo_valtesa, "Istruzioni.", 1, "griglia-prova", modello=modello)
    )

    assert len(risultati) == len(domande)
    assert risultati[0].risposta is None and "OpenAI" in (risultati[0].errore or "")
    assert all(r.risposta is not None for r in risultati[1:])


def test_main_scrive_i_risultati_accanto_alle_risposte_vere(demo_valtesa, tmp_path, capsys):
    # La chiave finta di conftest supera il controllo; risponde il modello finto.
    domande = demo_valtesa["domande"]
    cerca = {"domanda": "massimale cena trasferta Milano"}
    modello = ModelloFinto(
        [chiama("cerca_documenti", cerca), chiama("query_db", {"sql": SQL})],
        cita_il_primo_passaggio,
        *[eco] * (2 * len(domande) - 1),
    )
    uscita = tmp_path / "griglia"

    assert griglia.main(["valtesa", "--uscita", str(uscita)], modello=modello) == 0

    [percorso] = uscita.glob("risultati-valtesa-*.md")
    testo = percorso.read_text(encoding="utf-8")
    # Al modello arrivano le istruzioni di DemoSeeder, seguite dalle regole fisse.
    istruzioni = griglia.leggi_istruzioni(demo_valtesa["costante_istruzioni"])
    assert all(c.istruzioni.startswith(istruzioni + "\n\n") for c in modello.chiamate)
    assert f"```text\n{istruzioni}\n```" in testo
    for d in domande:
        assert f"**Domanda:** {d['domanda']}" in testo
        assert f"**Risposta vera:** {d['risposta_vera']}" in testo
    assert testo.count("**Esito:** …") == 2 * len(domande)
    # Il primo lancio della domanda 1: sessione, strada, chunk citato e tool con gli argomenti.
    primo = vectorstore.cerca(demo_valtesa["agente"], cerca["domanda"], get_settings().top_k)[0]
    assert re.search(r"### Lancio 1 · sessione `griglia-\d{8}-\d{6}-d1-l1` · \d+,\d s", testo)
    assert f"**Strada:** documenti+dati · **Citazioni:** `{primo['id']}`" in testo
    assert f"query_db {json.dumps({'sql': SQL}, ensure_ascii=False)}" in testo
    assert f"Risultati in {percorso}" in capsys.readouterr().out


def test_senza_chiave_esce_prima_di_fare_qualunque_cosa(imposta, monkeypatch, tmp_path, capsys):
    imposta(openai_api_key="")
    monkeypatch.setattr(griglia, "carica_griglia", vietato)
    monkeypatch.setattr(griglia.chat, "rispondi", vietato)
    uscita = tmp_path / "griglia"

    assert griglia.main(["valtesa", "--uscita", str(uscita)]) == 1

    assert "Manca OPENAI_API_KEY" in capsys.readouterr().err
    assert not uscita.exists()


def test_senza_agenti_demo_preparati_esce_con_un_messaggio(tmp_path, capsys):
    uscita = tmp_path / "griglia"

    assert griglia.main(["valtesa", "--uscita", str(uscita)], modello=ModelloFinto()) == 1

    assert "uv run python -m scripts.seed_demo" in capsys.readouterr().err
    assert not uscita.exists()
