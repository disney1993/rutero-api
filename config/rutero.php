<?php

return [
    'free_owner_ruta_limit' => (int) env('FREE_OWNER_RUTA_LIMIT', env('FREE_OWNER_TRIP_LIMIT', 30)),

    // Cierre automático de viajes (comando rutas:auto-complete).
    'auto_complete' => [
        // trip_date/trip_time son horas "de reloj" introducidas por el usuario
        // en España; se interpretan en esta zona horaria, no en la de la app (UTC).
        'timezone' => env('RUTERO_TIMEZONE', 'Europe/Madrid'),
        // Velocidad media prudente para estimar la duración cuando solo hay km
        // escritos a mano (sin coordenadas, OSRM no pudo dar la duración).
        'fallback_speed_kmh' => (int) env('RUTERO_FALLBACK_SPEED_KMH', 50),
        // Valores por defecto de cada propietario (configurables en Ajustes).
        'start_delay_min' => 10,
        'margin_min' => 5,
        'margin_pct' => 10,
        'default_duration_min' => 60,
    ],

    // Contraseñas de las cuentas de demo que crea DatabaseSeeder. En producción
    // son obligatorias (mín. 12 caracteres): el seeder se niega a correr sin
    // ellas para no dejar cuentas con contraseñas conocidas del repositorio.
    'seed' => [
        'admin_password' => env('SEED_ADMIN_PASSWORD'),
        'demo_password' => env('SEED_DEMO_PASSWORD'),
    ],

    // Media nacional diurna España ~1,07 €/km (FACUA 2025). Punto de partida
    // editable en el perfil del usuario.
    'default_price_per_km' => (float) env('DEFAULT_PRICE_PER_KM', 1.10),

    // auto = Google si hay GOOGLE_MAPS_API_KEY, si no Nominatim/OSRM.
    // google = forzar Google (si falla la búsqueda, cae a Nominatim).
    // nominatim = forzar los proveedores gratuitos.
    'geocoding_provider' => env('GEOCODING_PROVIDER', 'auto'),
];
