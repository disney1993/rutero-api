<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class TaxiTariffController extends Controller
{
    // Tarifas interurbanas máximas de taxi por comunidad autónoma, como
    // referencia para que cada usuario fije su precio/km (config/taxi_tariffs.php).
    // Son orientativas: los VTC tienen precios libres y cada usuario decide el suyo.
    public function index(): JsonResponse
    {
        $config = config('taxi_tariffs');

        return response()->json([
            'reviewed_at' => $config['reviewed_at'],
            'default_route_km_price' => $config['default_route_km_price'],
            'median_route_km_price' => $config['median_route_km_price'],
            // Ya vienen en orden alfabético en la configuración.
            'regions' => $config['regions'],
            'unverified' => $config['unverified'],
        ]);
    }
}
