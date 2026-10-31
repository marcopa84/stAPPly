<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;

/**
 * L'utente demo con i due agenti dei temi del corso. Gli id demo-valtesa e
 * demo-valdoria sono quelli che backend/scripts/seed_demo.py indicizza.
 *
 * È il template della demo: nessuno lo usa direttamente, perché ogni accesso con
 * le sue credenziali apre una sandbox con una copia di questi agenti
 * (App\Services\Demo\DemoSandbox). Per cambiare quello che i visitatori trovano
 * all'ingresso si cambia questo seeder e lo si rilancia.
 *
 * Si può rilanciare: aggiorna invece di duplicare. Le istruzioni contengono solo
 * il tema, perché le regole fisse (citazioni, rifiuto fuori tema…) le aggiunge il
 * backend; una correzione nata dalla griglia di prova va riportata anche qui.
 */
class DemoSeeder extends Seeder
{
    public const VALTESA_INSTRUCTIONS = <<<'TXT'
        Sei l'assistente dell'ufficio del personale di Valtesa S.r.l., per i dipendenti. Rispondi in italiano, in poche frasi.
        Per rispondere hai due strade:
        - domande su regole e procedure (ferie e permessi, trasferte e note spese, welfare e buoni pasto, ingresso e dotazioni, circolari, portale): usa cerca_documenti;
        - domande su dipendenti, assenze e note spese: usa query_db.
        Se una domanda ha bisogno di tutte e due, usa tutti e due i tool.
        TXT;

    public const VALDORIA_INSTRUCTIONS = <<<'TXT'
        Sei l'assistente della Biblioteca civica di Valdoria, per il personale delle tre sedi. Rispondi in italiano, in poche frasi.
        Per rispondere hai due strade:
        - domande su servizi e regole (sedi e orari, iscrizione e tessere, prestiti e rinnovi, prestito interbibliotecario, biblioteca digitale, sala studio, domande frequenti): usa cerca_documenti;
        - domande sul catalogo, sui prestiti e sugli utenti iscritti: usa query_db.
        Se una domanda ha bisogno di tutte e due, usa tutti e due i tool.
        TXT;

    public function run(): void
    {
        $user = User::firstOrNew(['email' => Config::string('stapply.demo.email')]);

        // Rilanciato, ripara anche l'account: l'utente demo è condiviso e niente di
        // quello che un visitatore fosse riuscito a cambiare deve sopravvivere al seed.
        // forceFill perché questi campi non sono assegnabili in massa.
        $user->forceFill([
            'name' => 'Utente demo',
            'password' => Config::string('stapply.demo.password'),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'demo_expires_at' => null,
        ])->save();

        $user->passkeys()->delete();

        $user->agents()->updateOrCreate(['backend_id' => 'demo-valtesa'], [
            'name' => 'Assistente Valtesa',
            'description' => "Ufficio del personale di un'azienda: ferie, trasferte, note spese, welfare.",
            'instructions' => self::VALTESA_INSTRUCTIONS,
            'is_demo' => true,
        ]);

        $user->agents()->updateOrCreate(['backend_id' => 'demo-valdoria'], [
            'name' => 'Biblioteca di Valdoria',
            'description' => 'Biblioteca civica: prestiti, sala studio, biblioteca digitale, catalogo.',
            'instructions' => self::VALDORIA_INSTRUCTIONS,
            'is_demo' => true,
        ]);
    }
}
