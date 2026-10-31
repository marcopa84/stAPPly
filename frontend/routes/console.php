<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Le sandbox demo scadute occupano spazio nel backend: si cancellano ogni ora.
// Senza cron (Herd) le cancella in parte anche ogni login demo.
Schedule::command('stapply:prune-demo')->hourly()->withoutOverlapping();
