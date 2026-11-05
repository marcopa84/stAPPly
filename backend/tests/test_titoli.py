"""Il titolo di una chat: la chiamata al modello, la pulizia della risposta, l'endpoint."""

import asyncio
from collections.abc import Iterator

import httpx
import openai
import pytest
from fastapi.testclient import TestClient

from app.core import agent, titoli
from app.core.errors import ModelloNonDisponibile, ProviderNonDisponibile
from app.main import create_app
from tests.modello_finto import ModelloFinto, scrive

CHIAVE = "chiave-di-prova"
DOMANDA = "Quante note spese sono ancora in attesa di approvazione?"
RISPOSTA = "Sono 56 note spese in attesa, per 2.184,40 €."


@pytest.fixture
def client(imposta) -> Iterator[TestClient]:
    imposta(internal_api_key=CHIAVE)
    with TestClient(create_app(), headers={"X-Internal-Key": CHIAVE}) as client:
        yield client


def genera(modello: ModelloFinto, message: str = DOMANDA, answer: str = RISPOSTA) -> str:
    return asyncio.run(titoli.genera_titolo(message, answer, modello=modello))


def test_il_titolo_arriva_dal_modello_senza_tool_e_con_lo_scambio_come_dato():
    modello = ModelloFinto(scrive("Note spese in attesa"))

    assert genera(modello) == "Note spese in attesa"

    chiamata = modello.chiamate[0]
    assert chiamata.istruzioni == titoli.ISTRUZIONI_TITOLO
    assert chiamata.tools == []
    ((ruolo, testo),) = chiamata.messaggi()
    assert ruolo == "user"
    assert f"<domanda>\n{DOMANDA}\n</domanda>" in testo
    assert f"<risposta>\n{RISPOSTA}\n</risposta>" in testo


def test_al_modello_arriva_solo_l_inizio_di_una_risposta_lunga():
    modello = ModelloFinto(scrive("Elenco dei dipendenti"))

    genera(modello, answer="riga\n" * 5000)

    ((_, testo),) = modello.chiamate[0].messaggi()
    assert len(testo) < 2 * titoli._MAX_CARATTERI_SCAMBIO + 100


@pytest.mark.parametrize(
    ("scritto", "titolo"),
    [
        ("«Note spese in attesa».", "Note spese in attesa"),
        ('"Ferie di Sara Conti"', "Ferie di Sara Conti"),
        ("# **Massimale della cena**", "Massimale della cena"),
        ("\n\nRinnovo del prestito 620\nAltro testo", "Rinnovo del prestito 620"),
        ("Buono   pasto\tin trasferta!", "Buono pasto in trasferta"),
    ],
)
def test_pulisci_titolo(scritto, titolo):
    assert titoli.pulisci_titolo(scritto) == titolo


def test_un_titolo_troppo_lungo_si_taglia_tra_due_parole():
    lungo = "Regole per le trasferte all'estero e il rimborso delle spese di viaggio in treno"

    titolo = titoli.pulisci_titolo(lungo)

    assert len(titolo) <= titoli.MAX_CARATTERI_TITOLO
    assert lungo.startswith(titolo)
    assert lungo[len(titolo)] == " "  # nessuna parola tagliata a metà


def test_senza_titolo_scritto_il_provider_non_e_disponibile():
    with pytest.raises(ProviderNonDisponibile):
        genera(ModelloFinto(scrive(" « » ")))


def test_un_errore_di_openai_diventa_provider_non_disponibile():
    errore = openai.APIConnectionError(
        request=httpx.Request("POST", "https://api.openai.com/v1/responses")
    )

    with pytest.raises(ProviderNonDisponibile):
        genera(ModelloFinto(errore))


def test_senza_chiave_il_modello_non_e_disponibile(imposta):
    imposta(openai_api_key="")

    with pytest.raises(ModelloNonDisponibile):
        asyncio.run(titoli.genera_titolo(DOMANDA, RISPOSTA))


def test_endpoint_titles(client: TestClient, monkeypatch):
    monkeypatch.setattr(agent, "crea_modello", lambda: ModelloFinto(scrive("Note spese in attesa")))

    risposta = client.post("/titles", json={"message": DOMANDA, "answer": RISPOSTA})

    assert risposta.status_code == 200
    assert risposta.json() == {"title": "Note spese in attesa"}


@pytest.mark.parametrize(
    "corpo",
    [
        {"message": "", "answer": RISPOSTA},
        {"message": DOMANDA},
        {"message": "x" * 4001, "answer": "a"},
    ],
)
def test_endpoint_titles_valida_il_corpo(client: TestClient, corpo):
    assert client.post("/titles", json=corpo).status_code == 422


def test_endpoint_titles_502_se_il_modello_non_risponde(client: TestClient, monkeypatch):
    monkeypatch.setattr(agent, "crea_modello", lambda: ModelloFinto(scrive("")))

    risposta = client.post("/titles", json={"message": DOMANDA, "answer": RISPOSTA})

    assert risposta.status_code == 502
    assert isinstance(risposta.json()["detail"], str)
