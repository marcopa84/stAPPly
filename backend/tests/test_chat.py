"""La chat con un modello finto: traccia, fonti, memoria, max_turns ed errori del provider."""

import asyncio
import csv
import re

import httpx
import openai
import pytest
from agents import ModelTracing

from app.core import agent, chat, embeddings, tables, vectorstore
from app.core.errors import ContenutoNonValido, ProviderNonDisponibile
from tests.conftest import NOTE_SPESE, messaggi
from tests.modello_finto import ModelloFinto, chiama, cita_il_primo_passaggio, scrive

ISTRUZIONI = "Sei l'assistente dell'ufficio del personale di Valtesa S.r.l."
DOMANDA = "massimale cena trasferta Milano"
SQL_IN_ATTESA = "SELECT ROUND(SUM(importo), 2) AS totale FROM note_spese WHERE stato = 'in attesa'"


def rispondi(agent_id: str, message: str, modello: ModelloFinto, session_id: str = "s1"):
    return asyncio.run(
        chat.rispondi(
            agent_id, session_id, message, ISTRUZIONI, "Assistente Valtesa", modello=modello
        )
    )


def totale_a_mano(stato: str) -> float:
    """La SELECT di SQL_IN_ATTESA rifatta in Python sul CSV (criterio 4 di CONSEGNA.md)."""
    with NOTE_SPESE.open(encoding="utf-8") as file:
        return round(
            sum(float(r["importo"]) for r in csv.DictReader(file) if r["stato"] == stato), 2
        )


def test_domanda_sui_documenti_cita_un_chunk(valtesa):
    modello = ModelloFinto(
        [chiama("cerca_documenti", {"domanda": DOMANDA})], cita_il_primo_passaggio
    )

    risposta = rispondi(valtesa, "Qual è il massimale per una cena a Milano?", modello)

    assert risposta.status == "ok"
    assert risposta.route == "documenti"
    assert risposta.tools == [{"name": "cerca_documenti", "arguments": {"domanda": DOMANDA}}]
    primo = vectorstore.cerca(valtesa, DOMANDA, 4)[0]
    assert f"[{primo['id']}]" in risposta.answer
    assert risposta.sources == [
        {
            "id": primo["id"],
            "document_id": primo["document_id"],
            "filename": primo["source"],
            "text": primo["testo"],
        }
    ]
    # Al modello i passaggi arrivano delimitati, con l'id da citare e il nome del file.
    output = modello.chiamate[1].output_tool()[0]
    assert output.startswith(f'--- [{primo["id"]}] (fonte: "{primo["source"]}") ---\n')


def test_domanda_fuori_tema_rifiutata_senza_tool(valtesa):
    modello = ModelloFinto(scrive("Posso rispondere solo sulle regole del personale di Valtesa."))

    risposta = rispondi(valtesa, "Chi ha vinto i mondiali del 2006?", modello)

    assert modello.chiamate[0].nomi_tool == {"cerca_documenti", "query_db"}  # li aveva
    assert (risposta.route, risposta.tools, risposta.sources) == ("nessuna", [], [])
    assert risposta.answer.startswith("Posso rispondere solo")


def test_domanda_che_chiede_documenti_e_dati_chiama_tutti_e_due_i_tool(valtesa):
    modello = ModelloFinto(
        [
            chiama("cerca_documenti", {"domanda": DOMANDA}),
            chiama("query_db", {"sql": SQL_IN_ATTESA}),
        ],
        cita_il_primo_passaggio,
    )

    risposta = rispondi(valtesa, "Massimale della cena e note spese in attesa?", modello)

    assert risposta.route == "documenti+dati"
    assert [t["name"] for t in risposta.tools] == ["cerca_documenti", "query_db"]
    assert risposta.tools[1]["arguments"] == {"sql": SQL_IN_ATTESA}
    # Il numero che arriva al modello coincide con la stessa somma fatta a mano.
    assert modello.chiamate[1].output_tool()[1] == f"totale\n{totale_a_mano('in attesa')}"


def test_un_errore_di_query_db_torna_al_modello_come_testo(valtesa):
    modello = ModelloFinto(
        [chiama("query_db", {"sql": "DELETE FROM note_spese"})],
        scrive("Non sono riuscito a leggere i dati."),
    )

    risposta = rispondi(valtesa, "Cancella le note spese", modello)

    assert modello.chiamate[1].output_tool() == [
        "Errore: Sono ammesse solo query di lettura: SELECT, oppure WITH … SELECT."
    ]
    assert risposta.route == "dati"
    conta = tables.esegui_select(valtesa, "SELECT COUNT(*) FROM note_spese", 1)
    assert conta["rows"] == [[401]]  # dati intatti


def test_un_risultato_troppo_grande_di_query_db_torna_al_modello_come_errore(valtesa):
    sql = "SELECT zeroblob(20000) AS dati"  # 40 000 caratteri in esadecimale
    modello = ModelloFinto([chiama("query_db", {"sql": sql})], scrive("Non ci sono riuscito."))

    rispondi(valtesa, "Fammi vedere i dati", modello)

    assert modello.chiamate[1].output_tool() == [
        f"Errore: Risultato troppo grande (oltre {agent.MAX_CARATTERI_QUERY_DB} caratteri): "
        "seleziona meno colonne o aggiungi un LIMIT."
    ]


def test_max_turns(valtesa, imposta):
    imposta(max_turns=2)
    sql = "SELECT COUNT(*) FROM note_spese WHERE stato = 'in attesa'"
    modello = ModelloFinto(
        [chiama("query_db", {"sql": sql})], [chiama("query_db", {"sql": sql + " AND 1"})]
    )

    risposta = rispondi(valtesa, "Quante note spese sono in attesa?", modello)

    assert risposta.status == "max_turns"
    assert risposta.answer == chat.MESSAGGIO_MAX_TURNS
    assert risposta.route == "dati"
    assert [t["arguments"]["sql"] for t in risposta.tools] == [sql, sql + " AND 1"]
    assert risposta.sources == []


def test_memoria_della_sessione(valtesa):
    modello = ModelloFinto(
        scrive("Piacere, Marco."),
        scrive("Ti chiami Marco."),
        scrive("Non lo so."),
        scrive("Non lo so."),
    )

    rispondi(valtesa, "Mi chiamo Marco.", modello, session_id="s1")
    rispondi(valtesa, "Come mi chiamo?", modello, session_id="s1")
    rispondi(valtesa, "Come mi chiamo?", modello, session_id="s2")
    rispondi("valdoria", "Come mi chiamo?", modello, session_id="s1")

    # Stessa sessione: il modello riceve anche lo scambio di prima.
    assert modello.chiamate[1].messaggi() == [
        ("user", "Mi chiamo Marco."),
        ("assistant", "Piacere, Marco."),
        ("user", "Come mi chiamo?"),
    ]
    # Un'altra sessione, o la stessa session_id su un altro agente, parte da zero.
    assert modello.chiamate[2].messaggi() == [("user", "Come mi chiamo?")]
    assert modello.chiamate[3].messaggi() == [("user", "Come mi chiamo?")]


def test_al_modello_arrivano_solo_gli_ultimi_turni_della_sessione(valtesa, imposta):
    imposta(session_history_turns=2)
    sql = {"sql": SQL_IN_ATTESA}
    modello = ModelloFinto(
        [chiama("query_db", sql)],
        scrive("Prima risposta."),
        scrive("Seconda risposta."),
        [chiama("query_db", sql)],
        scrive("Terza risposta."),
        scrive("Quarta risposta."),
    )

    for domanda in ("Prima?", "Seconda?", "Terza?", "Quarta?"):
        rispondi(valtesa, domanda, modello)

    ultima = modello.chiamate[-1]
    assert ultima.messaggi() == [
        ("user", "Seconda?"),
        ("assistant", "Seconda risposta."),
        ("user", "Terza?"),
        ("assistant", "Terza risposta."),
        ("user", "Quarta?"),
    ]
    # Il taglio cade prima di un messaggio dell'utente: il turno 3 arriva intero, con la
    # chiamata di query_db e il suo output.
    assert ultima.input[0]["role"] == "user"
    chiamate = [i["call_id"] for i in ultima.input if i.get("type") == "function_call"]
    risultati = [i["call_id"] for i in ultima.input if i.get("type") == "function_call_output"]
    assert len(chiamate) == 1
    assert chiamate == risultati
    # In sessions.db resta tutta la conversazione.
    domande = [m["content"] for m in messaggi(f"{valtesa}:s1") if m.get("role") == "user"]
    assert domande == ["Prima?", "Seconda?", "Terza?", "Quarta?"]


@pytest.mark.parametrize(("turni", "primo"), [(1, 4), (2, 2), (3, 0), (10, 0)])
def test_ultimi_turni_taglia_prima_di_un_messaggio_dell_utente(turni, primo):
    storia = [
        {"role": "user", "content": "a"},
        {"role": "assistant", "content": "b"},
        {"role": "user", "content": "c"},
        {"type": "function_call", "call_id": "1", "name": "query_db", "arguments": "{}"},
        {"role": "user", "content": "d"},
        {"type": "reasoning", "id": "rs_1", "summary": []},
        {"role": "assistant", "content": "e"},
    ]
    nuovi = [{"role": "user", "content": "f"}]
    assert chat.ultimi_turni(turni)(storia, nuovi) == storia[primo:] + nuovi


class ModelloLento(ModelloFinto):
    """Risponde con i passi scritti a mano, poi non risponde più: un provider bloccato."""

    async def get_response(self, *argomenti, **opzioni):
        if not self.passi:
            await asyncio.sleep(60)
        return await super().get_response(*argomenti, **opzioni)


def test_un_turno_oltre_il_tempo_massimo_si_ferma_e_non_resta_in_memoria(valtesa, monkeypatch):
    monkeypatch.setattr(chat, "TEMPO_MASSIMO_TURNO", 2.0)
    modello = ModelloLento([chiama("query_db", {"sql": SQL_IN_ATTESA})])

    with pytest.raises(ProviderNonDisponibile, match="non ha risposto in tempo"):
        rispondi(valtesa, "Quante note spese sono in attesa?", modello)

    assert len(modello.chiamate) == 1  # la seconda chiamata è rimasta in attesa
    # In memoria resta la parte di turno già fatta (domanda e query), non una risposta che
    # l'utente non ha mai visto.
    memoria = messaggi(f"{valtesa}:s1")
    assert [m.get("role") or m["type"] for m in memoria] == [
        "user",
        "function_call",
        "function_call_output",
    ]


def test_le_istruzioni_finali_sono_quelle_di_laravel_piu_le_regole_fisse(valtesa):
    modello = ModelloFinto(scrive("Ciao."))
    rispondi(valtesa, "Ciao", modello)
    istruzioni = modello.chiamate[0].istruzioni
    assert istruzioni is not None
    assert istruzioni.startswith(ISTRUZIONI)
    assert agent.REGOLE_FISSE in istruzioni


def test_query_db_assente_se_l_agente_non_ha_tabelle(valtesa):
    tables.cancella_tabella(valtesa, "note_spese")
    modello = ModelloFinto(scrive("Ciao."))
    rispondi(valtesa, "Ciao", modello)
    assert modello.chiamate[0].nomi_tool == {"cerca_documenti"}


def test_descrizione_di_query_db_con_lo_schema_reale(valtesa):
    modello = ModelloFinto(scrive("Ciao."))
    rispondi(valtesa, "Ciao", modello)
    assert tables.schema_testo(valtesa) in modello.chiamate[0].tool("query_db").description


def test_tracing_come_da_impostazioni(valtesa):
    modello = ModelloFinto(scrive("Ciao."))
    rispondi(valtesa, "Ciao", modello)  # conftest spegne le tracce: OPENAI_AGENTS_DISABLE_TRACING
    assert modello.chiamate[0].tracing == ModelTracing.DISABLED


def errore_api() -> openai.APIConnectionError:
    return openai.APIConnectionError(
        request=httpx.Request("POST", "https://api.openai.com/v1/responses")
    )


def errore_avvolto() -> RuntimeError:
    errore = RuntimeError("avvolto")
    errore.__cause__ = errore_api()
    return errore


@pytest.mark.parametrize("errore", [errore_api, errore_avvolto], ids=["diretto", "avvolto"])
def test_errore_del_provider(valtesa, errore):
    with pytest.raises(ProviderNonDisponibile, match="OpenAI"):
        rispondi(valtesa, "Ciao", ModelloFinto(errore()))


def test_errore_degli_embedding_dentro_il_tool_non_torna_al_modello(valtesa, monkeypatch):
    def embedding_giu(testi: list[str]) -> list[list[float]]:
        raise ProviderNonDisponibile("Il servizio di embedding (OpenAI) non risponde.")

    monkeypatch.setattr(embeddings, "embed", embedding_giu)
    modello = ModelloFinto([chiama("cerca_documenti", {"domanda": DOMANDA})])

    with pytest.raises(ProviderNonDisponibile, match="embedding"):
        rispondi(valtesa, "Massimale cena?", modello)
    assert len(modello.chiamate) == 1  # nessun secondo giro col messaggio d'errore


def test_argomenti_illeggibili_del_modello(valtesa):
    modello = ModelloFinto([chiama("query_db", "{non è json")])
    with pytest.raises(ProviderNonDisponibile, match="inatteso"):
        rispondi(valtesa, "Quante note spese?", modello)


def test_id_non_validi():
    with pytest.raises(ContenutoNonValido):
        rispondi("valtesa", "Ciao", ModelloFinto(), session_id="a:b")
    with pytest.raises(ContenutoNonValido):
        rispondi("Valtesa", "Ciao", ModelloFinto())


CHUNK = {
    id_chunk: {"document_id": id_chunk[:-4], "source": "x.md", "testo": f"testo {id_chunk}"}
    for id_chunk in ("faq-000", "faq-001", "ferie-002")
}


@pytest.mark.parametrize(
    ("answer", "citati"),
    [
        ("Trenta giorni [faq-001], rinnovabili [faq-000].", ["faq-001", "faq-000"]),
        ("Così [faq-000]. E ancora [faq-000].", ["faq-000"]),
        ("Più fonti [faq-001, ferie-002].", ["faq-001", "ferie-002"]),
        ("Un id inventato [faq-999] e uno vero [ferie-002].", ["ferie-002"]),
        ("Nessuna citazione.", []),
    ],
)
def test_fonti_in_ordine_di_citazione_senza_doppioni(answer, citati):
    fonti = chat.fonti(answer, CHUNK)
    assert [f["id"] for f in fonti] == citati
    for fonte in fonti:
        assert fonte == {
            "id": fonte["id"],
            "document_id": CHUNK[fonte["id"]]["document_id"],
            "filename": "x.md",
            "text": f"testo {fonte['id']}",
        }


def test_le_fonti_vengono_solo_da_questo_turno(valtesa):
    modello = ModelloFinto(
        [chiama("cerca_documenti", {"domanda": DOMANDA})],
        cita_il_primo_passaggio,
        lambda c: scrive(f"Come ti dicevo {re.findall(r'\[[a-z0-9-]+\]', c.messaggi()[1][1])[0]}."),
    )
    rispondi(valtesa, "Massimale cena?", modello)
    seconda = rispondi(valtesa, "Me lo ripeti?", modello)
    # Il chunk citato di nuovo non è tra quelli restituiti in questo turno: niente fonte.
    assert seconda.route == "nessuna"
    assert "[" in seconda.answer
    assert seconda.sources == []
