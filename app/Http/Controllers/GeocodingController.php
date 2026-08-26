<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Servicios públicos y gratuitos, sin necesidad de API key:
// - Nominatim (OpenStreetMap) para buscar direcciones reales.
// - OSRM (demo pública) para calcular la distancia real por carretera.
// Ambos son servicios de demostración compartidos: no tienen SLA ni
// garantía de disponibilidad para tráfico alto. Si la app crece, conviene
// pasar a un proveedor de pago (Google, Mapbox, HERE) o autoalojarlos.
class GeocodingController extends Controller
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const OSRM_URL = 'https://router.project-osrm.org/route/v1/driving';
    // Nominatim exige un User-Agent que identifique la aplicación.
    private const USER_AGENT = 'RuteroApp/1.0 (contacto: soporte@rutero.local)';

    public function search(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:3|max:200',
        ]);

        $query = trim($request->input('q'));
        $cacheKey = 'geocode:search:' . md5(strtolower($query));

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        try {
            $resp = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(6)
                ->get(self::NOMINATIM_URL, [
                    'q' => $query,
                    'format' => 'json',
                    'countrycodes' => 'es',
                    'addressdetails' => 0,
                    'limit' => 6,
                ]);

            if (! $resp->ok()) {
                throw new \RuntimeException('Nominatim respondió ' . $resp->status());
            }

            $results = collect($resp->json())->map(fn ($item) => [
                'display_name' => $item['display_name'],
                'lat' => (float) $item['lat'],
                'lon' => (float) $item['lon'],
            ])->values()->all();
        } catch (\Throwable $e) {
            Log::warning('Nominatim search failed: ' . $e->getMessage());
            return response()->json(['message' => 'No se pudo buscar la dirección ahora mismo. Puedes escribirla a mano.'], 503);
        }

        // Solo se cachean los éxitos: un fallo puntual del servicio externo
        // no debe "congelarse" durante 24h para todo el mundo.
        Cache::put($cacheKey, $results, now()->addDay());

        return response()->json($results);
    }

    public function distance(Request $request)
    {
        $data = $request->validate([
            'origin_lat' => 'required|numeric|between:-90,90',
            'origin_lng' => 'required|numeric|between:-180,180',
            'destination_lat' => 'required|numeric|between:-90,90',
            'destination_lng' => 'required|numeric|between:-180,180',
        ]);

        $cacheKey = 'geocode:distance:' . md5(implode(',', $data));

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        try {
            $coords = sprintf(
                '%F,%F;%F,%F',
                $data['origin_lng'],
                $data['origin_lat'],
                $data['destination_lng'],
                $data['destination_lat']
            );

            $resp = Http::timeout(8)->get(self::OSRM_URL . '/' . $coords, [
                'overview' => 'false',
            ]);

            if (! $resp->ok() || $resp->json('code') !== 'Ok') {
                throw new \RuntimeException('OSRM respondió ' . $resp->status());
            }

            $route = $resp->json('routes.0');
            if (! $route) {
                throw new \RuntimeException('OSRM no devolvió ninguna ruta');
            }

            $result = [
                'distance_km' => round($route['distance'] / 1000, 1),
                'duration_min' => round($route['duration'] / 60),
            ];
        } catch (\Throwable $e) {
            Log::warning('OSRM distance failed: ' . $e->getMessage());
            return response()->json(['message' => 'No se pudo calcular la distancia ahora mismo. Puedes introducirla a mano.'], 503);
        }

        Cache::put($cacheKey, $result, now()->addDay());

        return response()->json($result);
    }
}
