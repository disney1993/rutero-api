<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Servicios públicos y gratuitos, sin necesidad de API key:
// - Nominatim (OpenStreetMap) para buscar direcciones reales.
// - OSRM (demo pública) para calcular la distancia real por carretera
//   (distance_km + duration_min).
// Ambos son servicios de demostración compartidos: no tienen SLA ni
// garantía de disponibilidad para tráfico alto.
// TODO: si se configura GOOGLE_MAPS_API_KEY, usar Distance Matrix / Routes
// como primario o fallback, y deep-link de navegación en la app.
/*
 * Direcciones: Photon (Komoot, datos de OpenStreetMap), pensado para el
 * autocompletado mientras se escribe, y Nominatim como respaldo si Photon
 * falla o no encuentra nada (Nominatim prohíbe el autocompletado como uso
 * principal). PHOTON_URL permite apuntar a un Photon autoalojado.
 * TODO(rutero): OSRM de demostración no es apto para producción.
 *   router.project-osrm.org es un servicio de pruebas (1 petición/s, sin
 *   garantías). Autoalojar OSRM con el extracto de España o usar
 *   openrouteservice. Ver el apartado 2.5 del TFM.
 */
class GeocodingController extends Controller
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const OSRM_URL = 'https://router.project-osrm.org/route/v1/driving';
    // Nominatim exige un User-Agent que identifique la aplicación.
    private const USER_AGENT = 'RuteroApp/1.0 (contacto: soporte@rutero.local)';

    /** Photon: resultados de España con el mismo formato que Nominatim. */
    private function searchPhoton(string $query): array
    {
        $resp = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(5)
            ->get(config('rutero.geocoding.photon_url'), [
                'q' => $query,
                'limit' => 8,
                // Península, Baleares y Canarias; luego se filtra por país.
                'bbox' => '-18.2,27.6,4.4,43.9',
            ]);
        if (! $resp->ok()) {
            throw new \RuntimeException('Photon respondió '.$resp->status());
        }

        return collect($resp->json('features') ?? [])
            ->filter(fn ($f) => ($f['properties']['countrycode'] ?? null) === 'ES')
            ->map(function ($f) {
                $p = $f['properties'];
                $street = $p['street'] ?? null;
                $first = $street
                    ? trim($street.' '.($p['housenumber'] ?? ''))
                    : ($p['name'] ?? null);
                $parts = array_filter([
                    $first,
                    ($p['name'] ?? null) !== $first && $street ? ($p['name'] ?? null) : null,
                    $p['city'] ?? ($p['town'] ?? ($p['village'] ?? null)),
                    $p['state'] ?? null,
                    $p['postcode'] ?? null,
                ]);

                return [
                    'display_name' => implode(', ', array_unique($parts)),
                    'lat' => (float) $f['geometry']['coordinates'][1],
                    'lon' => (float) $f['geometry']['coordinates'][0],
                ];
            })
            ->filter(fn ($r) => $r['display_name'] !== '')
            ->unique('display_name')
            ->take(6)
            ->values()
            ->all();
    }

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

        // 1) Photon; 2) si falla o no encuentra nada, Nominatim.
        try {
            $results = $this->searchPhoton($query);
            if ($results !== []) {
                Cache::put($cacheKey, $results, now()->addDay());

                return response()->json($results);
            }
        } catch (\Throwable $e) {
            Log::info('Photon search failed, falling back to Nominatim: '.$e->getMessage());
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
