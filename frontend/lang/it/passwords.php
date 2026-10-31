<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Messaggi per il reset della password
    |--------------------------------------------------------------------------
    |
    | Esiti restituiti dal password broker quando si chiede il link di reset
    | o si imposta una nuova password.
    |
    */

    'reset' => 'La password è stata reimpostata.',
    // Anche per un'email che non è registrata: la risposta non deve rivelarlo.
    'sent' => "Se l'indirizzo appartiene a un account, ti abbiamo inviato via email il link per reimpostare la password.",
    'throttled' => 'Attendi prima di riprovare.',
    'token' => 'Il link per reimpostare la password non è valido o è scaduto.',
    'user' => 'Non troviamo un utente con questo indirizzo email.',

];
