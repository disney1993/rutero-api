<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected const GOOGLE_CLIENT_ID = 'test-web-client.apps.googleusercontent.com';

    /**
     * Simula la respuesta de Google tokeninfo para un id_token válido emitido
     * para esta app; $claims cambia o añade campos. Con varias listas, cada
     * llamada recibe la siguiente.
     */
    protected function fakeGoogleToken(array ...$claimsPerCall): void
    {
        config(['services.google.client_id' => self::GOOGLE_CLIENT_ID]);

        $sequence = Http::sequence();
        foreach ($claimsPerCall ?: [[]] as $claims) {
            $sequence->push(array_merge([
                'aud' => self::GOOGLE_CLIENT_ID,
                'iss' => 'https://accounts.google.com',
                'exp' => (string) (time() + 3600),
                'email_verified' => 'true',
                'sub' => 'google-sub-'.md5(json_encode($claims)),
            ], $claims));
        }

        Http::fake(['https://oauth2.googleapis.com/tokeninfo*' => $sequence]);
    }
}
