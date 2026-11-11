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
// TODO(rutero): comprobar que el planificador está activo en Laravel Cloud.
//   Sin "php artisan schedule:run" cada minuto, el cierre automático no se
//   ejecuta. En Laravel Cloud se activa en el entorno (Scheduler); en un VPS,
//   con la línea de cron "* * * * * php artisan schedule:run". Verificarlo
//   antes de las pruebas con usuarios (ver capítulo 4 del TFM).
Schedule::command('rutas:auto-complete')->everyMinute()->withoutOverlapping();
