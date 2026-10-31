<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backend FastAPI
    |--------------------------------------------------------------------------
    |
    | Laravel è l'unico client del backend (contratto in docs/api.md). La chiave
    | deve coincidere con INTERNAL_API_KEY del backend. Il timeout è alto perché
    | l'upload calcola gli embedding e la chat può fare più passaggi col modello.
    |
    */

    'backend' => [
        'url' => env('STAPPLY_BACKEND_URL', 'http://127.0.0.1:8001'),
        'key' => env('STAPPLY_BACKEND_KEY', ''),
        'timeout' => (int) env('STAPPLY_BACKEND_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limiti
    |--------------------------------------------------------------------------
    |
    | Gli stessi del backend (MAX_UPLOAD_MB, MAX_DOCUMENTS_PER_AGENT,
    | MAX_TABLES_PER_AGENT): qui servono a fermare subito un file sbagliato e a
    | mostrare i limiti nell'interfaccia. L'ultima parola resta al backend, che
    | controlla anche la firma del file.
    |
    */

    'max_upload_mb' => 10,

    'max_documents' => 20,

    'max_tables' => 10,

    'document_extensions' => ['txt', 'md', 'pdf', 'docx', 'html', 'htm'],

    'table_extensions' => ['csv', 'xlsx'],

    /*
    |--------------------------------------------------------------------------
    | Costi, per utente
    |--------------------------------------------------------------------------
    |
    | Tutti gli agenti usano la chiave OpenAI del progetto: ogni caricamento
    | paga gli embedding, ogni messaggio una o più chiamate al modello. Oltre
    | questi numeri l'interfaccia chiede di aspettare (App\Support\UsageLimits).
    | Anche "Riprova" nella chat conta come un messaggio, e anche la
    | sostituzione di un file come un caricamento.
    |
    */

    'max_agents' => 10,

    'uploads_per_hour' => 20,

    'uploads_per_day' => 60,

    'chat_messages_per_minute' => 20,

    'chat_messages_per_day' => 300,

    /*
    |--------------------------------------------------------------------------
    | Demo
    |--------------------------------------------------------------------------
    |
    | DemoSeeder crea l'utente demo (il template) con gli agenti demo-valtesa e
    | demo-valdoria; la home mostra le sue credenziali. Nessuno lo usa
    | direttamente: ogni accesso con queste credenziali apre una sandbox, un
    | utente temporaneo con la sua copia degli agenti demo (il backend copia i
    | vettori già calcolati, senza embedding nuovi). Così i visitatori
    | modificano tutto senza disturbarsi, e al prossimo accesso ripartono dai
    | valori iniziali.
    |
    | Le sandbox hanno i limiti di costo per utente qui sopra, più quelli
    | propri della demo, più bassi: per sandbox (agenti, file, dimensione) e
    | comuni a tutte (messaggi al minuto, caricamenti all'ora). Un indirizzo IP
    | apre al massimo sandboxes_per_ip_per_hour sandbox all'ora, così nessuno
    | da solo riempie la demo per tutti.
    |
    */

    'demo' => [
        'email' => 'demo@stapply.test',
        'password' => env('STAPPLY_DEMO_PASSWORD', 'stapply-demo'),

        // Durata di una sandbox; il logout la chiude subito.
        'sandbox_minutes' => 120,

        // Sandbox aperte nello stesso momento: oltre, il login demo chiede di riprovare.
        'max_sandboxes' => 50,

        // Sandbox aperte da uno stesso IP in un'ora.
        'sandboxes_per_ip_per_hour' => 3,

        // Sandbox scadute cancellate a ogni login demo, oltre al giro orario di
        // stapply:prune-demo (Herd non ha cron).
        'prune_on_login' => 3,

        // Per sandbox: le due copie degli agenti demo contano.
        'max_agents' => 4,
        'max_documents' => 10,
        'max_tables' => 6,
        'max_upload_mb' => 2,

        // Comuni a tutte le sandbox.
        'chat_messages_per_minute' => 30,
        'uploads_per_hour' => 30,
    ],

];
