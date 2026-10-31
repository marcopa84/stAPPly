from types import SimpleNamespace

import httpx2
import openai
import pytest

from app.core import embeddings
from app.core.embeddings import embed  # quella vera: la fixture sostituisce solo embeddings.embed
from app.core.errors import ProviderNonDisponibile


class ClientFinto:
    """Risponde come client.embeddings.create, con i dati in ordine inverso (l'API non
    promette l'ordine: conta `index`)."""

    def __init__(self, errore: Exception | None = None):
        self.lotti: list[list[str]] = []
        self.errore = errore
        self.embeddings = self

    def create(self, model: str, input: list[str]) -> SimpleNamespace:
        if self.errore:
            raise self.errore
        self.lotti.append(input)
        dati = [SimpleNamespace(index=i, embedding=[float(len(t))]) for i, t in enumerate(input)]
        return SimpleNamespace(data=dati[::-1], usage=SimpleNamespace(total_tokens=len(input)))


@pytest.fixture
def client_finto(monkeypatch):
    def installa(errore: Exception | None = None) -> ClientFinto:
        client = ClientFinto(errore)
        monkeypatch.setattr(embeddings, "_client", lambda api_key: client)
        return client

    return installa


def test_embed_a_lotti_nell_ordine_dei_testi(imposta, client_finto):
    imposta(embedding_batch=2)
    client = client_finto()

    vettori = embed(["a", "bb", "ccc", "dddd", "eeeee"])

    assert vettori == [[1.0], [2.0], [3.0], [4.0], [5.0]]
    assert client.lotti == [["a", "bb"], ["ccc", "dddd"], ["eeeee"]]


def test_embed_di_nessun_testo_non_chiama_il_provider(client_finto):
    client = client_finto()
    assert embed([]) == []
    assert client.lotti == []


def test_errore_del_provider_diventa_provider_non_disponibile(client_finto):
    richiesta = httpx2.Request("POST", "https://api.openai.com/v1/embeddings")
    client_finto(openai.APIConnectionError(request=richiesta))
    with pytest.raises(ProviderNonDisponibile, match="OpenAI"):
        embed(["testo"])


def test_senza_chiave_provider_non_disponibile_senza_rete(imposta):
    imposta(openai_api_key="")
    embeddings._client.cache_clear()
    with pytest.raises(ProviderNonDisponibile):
        embed(["testo"])
