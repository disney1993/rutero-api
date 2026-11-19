<?php

namespace App\Console\Commands;

use App\Services\RutaAutoCompleter;
use Illuminate\Console\Command;

class AutoCompleteRutas extends Command
{
    protected $signature = 'rutas:auto-complete';

    protected $description = 'Marca como completadas (pago en efectivo) las rutas pendientes cuyo viaje ya ha terminado';

    public function handle(RutaAutoCompleter $completer): int
    {
        $closed = $completer->run();

        $this->info("Rutas cerradas automáticamente: {$closed}");

        return self::SUCCESS;
    }
}
