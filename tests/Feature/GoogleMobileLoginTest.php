<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleMobileLoginTest extends TestCase
{
    use RefreshDatabase;

    private function login(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/google/mobile', ['id_token' => 'token']);
    }

    public function test_a_token_issued_for_this_app_logs_in(): void
    {
        $this->fakeGoogleToken(['email' => 'ok@example.test']);

        $this->login()->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_the_android_and_ios_client_ids_are_also_accepted(): void
    {
        config(['services.google.mobile_client_ids' => 'android-id, ios-id']);
        $this->fakeGoogleToken(['email' => 'a@example.test', 'aud' => 'android-id'], ['email' => 'i@example.test', 'aud' => 'ios-id']);

        $this->login()->assertOk();
        $this->login()->assertOk();
    }

    /** Cada caso es un token que Google da por válido pero que no debe abrir sesión. */
    public static function rejectedTokens(): array
    {
        return [
            'token de otra app' => [['aud' => 'otra-app.apps.googleusercontent.com']],
            'emisor que no es Google' => [['iss' => 'https://evil.example']],
            'caducado' => [['exp' => (string) (time() - 10)]],
            'email sin verificar en Google' => [['email_verified' => 'false']],
            'sin email' => [['email' => null]],
        ];
    }

    #[DataProvider('rejectedTokens')]
    public function test_tokens_that_are_not_for_this_app_or_not_trustworthy_are_rejected(array $claims): void
    {
        $victim = User::factory()->create(['email' => 'victima@example.test']);
        $this->fakeGoogleToken(array_merge(['email' => 'victima@example.test'], $claims));

        $this->login()->assertUnauthorized();

        $this->assertSame(0, $victim->tokens()->count());
        $this->assertSame(1, User::count());
    }

    public function test_a_token_google_rejects_is_rejected(): void
    {
        config(['services.google.client_id' => 'web-id']);
        Http::fake(['https://oauth2.googleapis.com/tokeninfo*' => Http::response(['error' => 'invalid_token'], 400)]);

        $this->login()->assertUnauthorized();
    }

    public function test_without_client_ids_configured_google_login_is_refused(): void
    {
        config(['services.google.client_id' => null, 'services.google.mobile_client_ids' => null]);
        Http::fake();

        $this->login()->assertStatus(503);
        Http::assertNothingSent();
    }
}
