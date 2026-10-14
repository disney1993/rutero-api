<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cierre automático de viajes. Requiere que el planificador esté corriendo:
// en producción, un cron del sistema "* * * * * php artisan schedule:run";
// en local, "php artisan schedule:work".
Schedule::command('rutas:auto-complete')->everyMinute()->withoutOverlapping();
