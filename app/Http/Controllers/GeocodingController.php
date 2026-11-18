<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Geocoding + driving distance for Spain.
 *
 * Preferred provider: Google Places Text Search + Distance Matrix
 * (when GOOGLE_MAPS_API_KEY is set).
 * Fallback: Nominatim (OSM) + OSRM public demos.
 */
class GeocodingController extends Controller
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    private const OSRM_URL = 'https://router.project-osrm.org/route/v1/driving';

    private const GOOGLE_PLACES_TEXTSEARCH_URL = 'https://maps.googleapis.com/maps/api/place/textsearch/json';

    private const GOOGLE_DISTANCE_MATRIX_URL = 'https://maps.googleapis.com/maps/api/distancematrix/json';

    private const USER_AGENT = 'RuteroApp/1.0 (contacto: soporte@rutero.local)';

    public function search(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:2|max:200',
        ]);

        $query = trim($request->input('q'));
        $isPostalCode = (bool) preg_match('/^\d{4,5}$/', $query);

        if (! $isPostalCode && mb_strlen($query) < 3) {
            return response()->json([
                'message' => 'The q field must be at least 3 characters.',
                'errors' => ['q' => ['The q field must be at least 3 characters.']],
            ], 422);
        }

        $cacheKey = 'geocode:search:v3:'.md5(strtolower($query).'|'.$this->providerPreference());

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            $cachedProvider = is_array($cached) && isset($cached[0]['provider'])
                ? (string) $cached[0]['provider']
                : 'cache';

            return response()->json($cached)->header('X-Geocode-Provider', $cachedProvider);
        }

        $results = null;
        $usedProvider = null;

        if ($this->shouldUseGoogle()) {
            try {
                $results = $this->searchWithGoogle($query, $isPostalCode);
                $usedProvider = 'google';
            } catch (\Throwable $e) {
                Log::warning('Google Places search failed: '.$e->getMessage());
            }
        }

        if ($results === null) {
            try {
                $results = $this->searchWithNominatim($query, $isPostalCode);
                $usedProvider = 'nominatim';
            } catch (\Throwable $e) {
                Log::warning('Nominatim search failed: '.$e->getMessage());

                return response()->json(['message' => 'No se pudo buscar la dirección ahora mismo. Puedes escribirla a mano.'], 503);
            }
        }

        Cache::put($cacheKey, $results, now()->addDay());

        return response()->json($results)->header('X-Geocode-Provider', $usedProvider ?? 'unknown');
    }

    public function distance(Request $request)
    {
        $data = $request->validate([
            'origin_lat' => 'required|numeric|between:-90,90',
            'origin_lng' => 'required|numeric|between:-180,180',
            'destination_lat' => 'required|numeric|between:-90,90',
            'destination_lng' => 'required|numeric|between:-180,180',
        ]);

        $cacheKey = 'geocode:distance:v3:'.md5(implode(',', $data).'|'.$this->providerPreference());

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached)->header(
                'X-Geocode-Provider',
                (string) ($cached['provider'] ?? 'cache')
            );
        }

        $result = null;
        $usedProvider = null;

        if ($this->shouldUseGoogle()) {
            try {
                $result = $this->distanceWithGoogle($data);
                $usedProvider = 'google';
            } catch (\Throwable $e) {
                Log::warning('Google Distance Matrix failed: '.$e->getMessage());
            }
        }

        if ($result === null) {
            try {
                $result = $this->distanceWithOsrm($data);
                $usedProvider = 'osrm';
            } catch (\Throwable $e) {
                Log::warning('OSRM distance failed: '.$e->getMessage());

                return response()->json(['message' => 'No se pudo calcular la distancia ahora mismo. Puedes introducirla a mano.'], 503);
            }
        }

        $result['provider'] = $usedProvider;
        Cache::put($cacheKey, $result, now()->addDay());

        return response()->json($result)->header('X-Geocode-Provider', $usedProvider ?? 'unknown');
    }

    public function status()
    {
        $keyConfigured = filled(config('services.google.maps_api_key'));
        $preferred = $this->providerPreference();
        $useGoogle = $this->shouldUseGoogle();

        return response()->json([
            'preferred' => $preferred,
            'google_configured' => $keyConfigured,
            'active_search' => $useGoogle ? 'google' : 'nominatim',
            'active_distance' => $useGoogle ? 'google' : 'osrm',
            'labels' => [
                'google' => 'Google Maps',
                'nominatim' => 'Nominatim (OpenStreetMap, gratis)',
                'osrm' => 'OSRM (ruta, gratis)',
            ],
            // Informative only — real remaining quota lives in Google Cloud Console.
            'billing' => [
                'summary' => 'Desde marzo 2025 el crédito fijo de 200 USD/mes ya no aplica. Cada SKU tiene un cupo gratis mensual; al superarlo se cobra.',
                'places_text_search' => 'Places Text Search (lo usamos para direcciones): cupo gratis típico ~5.000 eventos/mes (SKU Pro), luego cobro por evento.',
                'distance_matrix' => 'Distance Matrix Legacy (km entre puntos): ~10.000 elementos/mes gratis; 1 origen × 1 destino = 1 elemento.',
                'note' => 'Hace falta cuenta de facturación activa en Google Cloud aunque estés en el tramo gratis. El uso en caché de Rutero no consume cuota.',
                'metrics_url' => 'https://console.cloud.google.com/google/maps-apis/metrics',
                'pricing_url' => 'https://developers.google.com/maps/billing-and-pricing/pricing',
            ],
        ]);
    }

    private function providerPreference(): string
    {
        return strtolower((string) config('rutero.geocoding_provider', 'auto'));
    }

    private function shouldUseGoogle(): bool
    {
        $preference = $this->providerPreference();
        if ($preference === 'nominatim') {
            return false;
        }

        if ($preference === 'google') {
            return filled(config('services.google.maps_api_key'));
        }

        // auto
        return filled(config('services.google.maps_api_key'));
    }

    private function mapsKey(): string
    {
        return (string) config('services.google.maps_api_key');
    }

    private function searchWithGoogle(string $query, bool $isPostalCode): array
    {
        $searchQuery = $isPostalCode
            ? str_pad($query, 5, '0', STR_PAD_LEFT).' España'
            : $query;

        $resp = Http::timeout(8)->get(self::GOOGLE_PLACES_TEXTSEARCH_URL, [
            'query' => $searchQuery,
            'region' => 'es',
            'language' => 'es',
            'key' => $this->mapsKey(),
        ]);

        if (! $resp->ok()) {
            throw new \RuntimeException('Google Places HTTP '.$resp->status());
        }

        $status = $resp->json('status');
        if (! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            throw new \RuntimeException('Google Places status '.$status.': '.($resp->json('error_message') ?? ''));
        }

        return collect($resp->json('results') ?? [])
            ->take(8)
            ->map(function ($item) {
                $location = $item['geometry']['location'] ?? null;
                if (! $location) {
                    return null;
                }

                return [
                    'display_name' => $item['formatted_address'] ?? ($item['name'] ?? ''),
                    'lat' => (float) $location['lat'],
                    'lon' => (float) $location['lng'],
                    'postcode' => null,
                    'type' => $item['types'][0] ?? 'place',
                    'place_id' => $item['place_id'] ?? null,
                    'provider' => 'google',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function distanceWithGoogle(array $data): array
    {
        $origin = $data['origin_lat'].','.$data['origin_lng'];
        $destination = $data['destination_lat'].','.$data['destination_lng'];

        $resp = Http::timeout(8)->get(self::GOOGLE_DISTANCE_MATRIX_URL, [
            'origins' => $origin,
            'destinations' => $destination,
            'mode' => 'driving',
            'units' => 'metric',
            'language' => 'es',
            'region' => 'es',
            'key' => $this->mapsKey(),
        ]);

        if (! $resp->ok()) {
            throw new \RuntimeException('Google Distance Matrix HTTP '.$resp->status());
        }

        $status = $resp->json('status');
        if ($status !== 'OK') {
            throw new \RuntimeException('Google Distance Matrix status '.$status.': '.($resp->json('error_message') ?? ''));
        }

        $element = $resp->json('rows.0.elements.0');
        if (! $element || ($element['status'] ?? null) !== 'OK') {
            throw new \RuntimeException('Google Distance Matrix element status '.($element['status'] ?? 'missing'));
        }

        return [
            'distance_km' => round(($element['distance']['value'] ?? 0) / 1000, 1),
            'duration_min' => round(($element['duration']['value'] ?? 0) / 60),
        ];
    }

    private function searchWithNominatim(string $query, bool $isPostalCode): array
    {
        $params = [
            'format' => 'json',
            'countrycodes' => 'es',
            'addressdetails' => 1,
            'limit' => 8,
        ];

        if ($isPostalCode) {
            $params['postalcode'] = str_pad($query, 5, '0', STR_PAD_LEFT);
            $params['country'] = 'Spain';
        } else {
            $params['q'] = $query;
        }

        $resp = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(6)
            ->get(self::NOMINATIM_URL, $params);

        if (! $resp->ok()) {
            throw new \RuntimeException('Nominatim respondió '.$resp->status());
        }

        $results = collect($resp->json())->map(fn ($item) => [
            'display_name' => $item['display_name'],
            'lat' => (float) $item['lat'],
            'lon' => (float) $item['lon'],
            'postcode' => $item['address']['postcode'] ?? null,
            'type' => $item['type'] ?? ($item['class'] ?? null),
            'provider' => 'nominatim',
        ])->values()->all();

        if ($results === [] && $isPostalCode) {
            $fallback = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(6)
                ->get(self::NOMINATIM_URL, [
                    'q' => $query.', España',
                    'format' => 'json',
                    'countrycodes' => 'es',
                    'addressdetails' => 1,
                    'limit' => 8,
                ]);

            if ($fallback->ok()) {
                $results = collect($fallback->json())->map(fn ($item) => [
                    'display_name' => $item['display_name'],
                    'lat' => (float) $item['lat'],
                    'lon' => (float) $item['lon'],
                    'postcode' => $item['address']['postcode'] ?? null,
                    'type' => $item['type'] ?? ($item['class'] ?? null),
                    'provider' => 'nominatim',
                ])->values()->all();
            }
        }

        return $results;
    }

    private function distanceWithOsrm(array $data): array
    {
        $coords = sprintf(
            '%F,%F;%F,%F',
            $data['origin_lng'],
            $data['origin_lat'],
            $data['destination_lng'],
            $data['destination_lat']
        );

        $resp = Http::timeout(8)->get(self::OSRM_URL.'/'.$coords, [
            'overview' => 'false',
        ]);

        if (! $resp->ok() || $resp->json('code') !== 'Ok') {
            throw new \RuntimeException('OSRM respondió '.$resp->status());
        }

        $route = $resp->json('routes.0');
        if (! $route) {
            throw new \RuntimeException('OSRM no devolvió ninguna ruta');
        }

        return [
            'distance_km' => round($route['distance'] / 1000, 1),
            'duration_min' => round($route['duration'] / 60),
        ];
    }
}
