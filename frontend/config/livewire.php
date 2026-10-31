<?php

/*
|--------------------------------------------------------------------------
| Livewire
|--------------------------------------------------------------------------
|
| Solo le chiavi che stAPPly cambia: Livewire unisce questo file ai suoi
| default per chiave di primo livello, quindi temporary_file_upload va
| scritto per intero.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Upload temporanei
    |--------------------------------------------------------------------------
    |
    | Un file scelto in Documenti o Tabelle arriva prima qui, all'endpoint di
    | upload di Livewire, e poi va al backend (App\Livewire\Agents\FilesSection,
    | che lo cancella subito dopo, e subito anche se lo rifiuta). L'endpoint
    | scrive su disco anche se il file poi non viene mai mandato: per questo
    | accetta pochi caricamenti al minuto invece dei 60 di Livewire. Le regole
    | restano quelle di Livewire (fino a 12 MB): il limite vero, con il suo
    | messaggio, lo controlla FilesSection appena il file arriva.
    |
    */

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => null,
        'directory' => null,
        'middleware' => 'throttle:10,1',
        // stAPPly non mostra anteprime dei file caricati.
        'preview_mimes' => [],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
