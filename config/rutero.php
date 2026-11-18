<?php

return [
    'free_owner_ruta_limit' => (int) env('FREE_OWNER_RUTA_LIMIT', env('FREE_OWNER_TRIP_LIMIT', 30)),

    // Media nacional diurna España ~1,07 €/km (FACUA 2025). Redondeamos a 1,10
    // como valor de partida editable en el perfil del usuario.
    'default_price_per_km' => (float) env('DEFAULT_PRICE_PER_KM', 1.10),

    // auto = Google if GOOGLE_MAPS_API_KEY is set, otherwise Nominatim/OSRM
    // google = force Google (fails over to Nominatim/OSRM on error)
    // nominatim = force free providers
    'geocoding_provider' => env('GEOCODING_PROVIDER', 'auto'),
];
