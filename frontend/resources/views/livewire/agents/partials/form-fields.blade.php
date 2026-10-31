<flux:input wire:model="form.name" label="Nome" type="text" required autofocus maxlength="100" placeholder="Assistente dell'ufficio del personale" />

<flux:input wire:model="form.description" label="Descrizione" type="text" maxlength="255" placeholder="Ferie, trasferte, note spese e buoni pasto" badge="Facoltativa" />

<flux:field>
    <flux:label>Istruzioni</flux:label>

    <flux:description>
        Scrivi il tema: chi è l'agente, per chi lavora, quando cercare nei documenti e quando nelle tabelle.
        stAPPly aggiunge sempre le sue regole fisse: l'agente risponde solo con quello che trovano i tool,
        cita i passaggi con il loro id tra [ ], dice quando non trova la risposta e dove ha cercato,
        rifiuta le domande fuori tema senza cercare e si ferma se un tool sbaglia due volte di fila.
    </flux:description>

    <flux:textarea
        wire:model="form.instructions"
        rows="10"
        maxlength="8000"
        placeholder="Sei l'assistente dell'ufficio del personale di Valtesa S.r.l., per i dipendenti. Rispondi in italiano, in poche frasi."
    />

    <flux:error name="form.instructions" />
</flux:field>
