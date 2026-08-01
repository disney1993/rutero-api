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
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    // Si usas subdominios / múltiples orígenes, lista explícita:
    // 'allowed_origins' => ['http://localhost:3000', 'http://127.0.0.1:3000'],

    'allowed_origins_patterns' => [],

    // Debe incluir Authorization si envías el token en cabecera
    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'Accept', 'Origin'],

    'exposed_headers' => [],

    'max_age' => 0,

    // IMPORTANTE: permitir credenciales
    'supports_credentials' => true,

];
