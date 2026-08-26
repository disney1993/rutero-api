<?php
return [

    /*
    |--------------------------------------------------------------------------
    | Cross-origin resource sharing (CORS) configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Cambiar '*' por el origen exacto de tu frontend
    'allowed_origins' => array_filter(array_merge([
        'http://localhost:19006',
        'http://127.0.0.1:19006',
        'http://localhost:8081',
        'http://127.0.0.1:8081',
    ], [env('FRONTEND_URL')])),

    'allowed_origins_patterns' => [],

    // Debe incluir Authorization si envías el token en cabecera
    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'Accept', 'Origin'],

    'exposed_headers' => [],

    'max_age' => 0,

    // IMPORTANTE: permitir credenciales
    'supports_credentials' => true,

];
