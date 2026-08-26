<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_and_login()
    {
        $payload = [
            'first_name' => 'Owner',
            'last_name' => 'One',
            'avatar' => 'http://example.com/avatar.png',
            'email' => 'owner@example.test',
            'password' => 'Secret123!',
        ];

        $resp = $this->postJson('/api/register', $payload);
        $resp->assertStatus(200)->assertJsonStructure(['access_token', 'user']);
        $user = $resp->json('user');
        $this->assertEquals('user', $user['role']);
        $this->assertFalse($user['has_owner_capability']);
        $this->assertFalse($user['has_driver_capability']);

        $login = $this->postJson('/api/login', ['email' => $payload['email'], 'password' => $payload['password']]);
        $login->assertStatus(200)->assertJsonStructure(['access_token']);
    }

    public function test_register_accepts_second_last_name_and_avatar_color()
    {
        $payload = [
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'second_last_name' => 'García',
            'email' => 'ana@example.test',
            'password' => 'Secret123!',
            'avatar_color' => '#42A5F5',
        ];

        $resp = $this->postJson('/api/register', $payload);
        $resp->assertStatus(200);
        $user = $resp->json('user');
        $this->assertEquals('García', $user['second_last_name']);
        $this->assertEquals('#42A5F5', $user['avatar_color']);
        $this->assertEquals('Ana Pérez García', $user['name']);
    }

    public function test_register_assigns_random_avatar_color_when_not_sent()
    {
        $payload = [
            'first_name' => 'Luis',
            'last_name' => 'Gómez',
            'email' => 'luis@example.test',
            'password' => 'Secret123!',
        ];

        $resp = $this->postJson('/api/register', $payload);
        $resp->assertStatus(200);
        $this->assertMatchesRegularExpression('/^#[A-Fa-f0-9]{6}$/', $resp->json('user.avatar_color'));
    }

    public function test_register_rejects_invalid_names_and_avatar_color()
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'An4',
            'last_name' => 'Pérez',
            'email' => 'invalid@example.test',
            'password' => 'Secret123!',
            'avatar_color' => 'not-a-color',
        ]);

        $resp->assertStatus(422)->assertJsonValidationErrors(['first_name', 'avatar_color']);
    }

    public function test_authenticated_user_can_update_avatar_color()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Carla',
            'last_name' => 'Ruiz',
            'email' => 'carla@example.test',
            'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/user/avatar-color', ['avatar_color' => '#26A69A']);

        $resp->assertStatus(200);
        $this->assertEquals('#26A69A', $resp->json('avatar_color'));
    }

    public function test_update_avatar_color_rejects_invalid_format()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Carla',
            'last_name' => 'Ruiz',
            'email' => 'carla2@example.test',
            'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/user/avatar-color', ['avatar_color' => 'blue']);

        $resp->assertStatus(422)->assertJsonValidationErrors(['avatar_color']);
    }

    public function test_user_can_update_own_profile()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Marta', 'last_name' => 'Lopez',
            'email' => 'marta.profile@example.test', 'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/user', ['email' => 'marta.new@example.test', 'second_last_name' => 'Ruiz']);

        $resp->assertStatus(200)
            ->assertJsonPath('email', 'marta.new@example.test')
            ->assertJsonPath('second_last_name', 'Ruiz')
            ->assertJsonPath('name', 'Marta Lopez Ruiz');
    }

    public function test_update_profile_rejects_taken_email()
    {
        $this->postJson('/api/register', [
            'first_name' => 'Uno', 'last_name' => 'User',
            'email' => 'taken.profile@example.test', 'password' => 'Secret123!',
        ]);
        $register = $this->postJson('/api/register', [
            'first_name' => 'Dos', 'last_name' => 'User',
            'email' => 'free.profile@example.test', 'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/user', ['email' => 'taken.profile@example.test']);

        $resp->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_change_password_with_correct_current_password()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Pass', 'last_name' => 'Word',
            'email' => 'pass@example.test', 'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")->patchJson('/api/user/password', [
            'current_password' => 'Secret123!',
            'password' => 'NewSecret456!',
            'password_confirmation' => 'NewSecret456!',
        ]);
        $resp->assertStatus(200);

        $login = $this->postJson('/api/login', ['email' => 'pass@example.test', 'password' => 'NewSecret456!']);
        $login->assertStatus(200);
    }

    public function test_change_password_rejects_reusing_current_password()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Pass', 'last_name' => 'Same',
            'email' => 'passsame@example.test', 'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")->patchJson('/api/user/password', [
            'current_password' => 'Secret123!',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ]);
        $resp->assertStatus(422);
    }

    public function test_change_password_rejects_wrong_current_password()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Pass', 'last_name' => 'Word',
            'email' => 'pass2@example.test', 'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")->patchJson('/api/user/password', [
            'current_password' => 'wrongpass',
            'password' => 'NewSecret456!',
            'password_confirmation' => 'NewSecret456!',
        ]);
        $resp->assertStatus(422);
    }

    public function test_register_rejects_weak_passwords()
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'Weak', 'last_name' => 'Pass',
            'email' => 'weak@example.test', 'password' => 'secret123',
        ]);
        $resp->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_register_accepts_password_with_upper_number_and_symbol()
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'Strong', 'last_name' => 'Pass',
            'email' => 'strong@example.test', 'password' => 'Secret123!',
        ]);
        $resp->assertStatus(200);
    }

    public function test_change_password_rejects_weak_new_password()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Pass', 'last_name' => 'Weak',
            'email' => 'passweak@example.test', 'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")->patchJson('/api/user/password', [
            'current_password' => 'Secret123!',
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ]);
        $resp->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_register_defaults_price_per_km_to_075()
    {
        $resp = $this->postJson('/api/register', [
            'first_name' => 'Default', 'last_name' => 'Price',
            'email' => 'defaultprice@example.test', 'password' => 'Secret123!',
        ]);
        $resp->assertStatus(200)->assertJsonPath('user.price_per_km', 0.75);
    }

    public function test_updating_currency_alone_does_not_clear_price_per_km()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Curr', 'last_name' => 'Only',
            'email' => 'curronly@example.test', 'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');
        $this->assertEquals(0.75, $register->json('user.price_per_km'));

        $resp = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/user/preferences', ['currency' => 'USD']);

        $resp->assertStatus(200)
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('price_per_km', 0.75);
    }

    public function test_user_can_update_theme_and_language()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Theme', 'last_name' => 'User',
            'email' => 'themeuser@example.test', 'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/user/preferences', ['theme' => 'dark', 'language' => 'en']);

        $resp->assertStatus(200)
            ->assertJsonPath('theme', 'dark')
            ->assertJsonPath('language', 'en');
    }

    public function test_update_preferences_rejects_invalid_theme_and_language()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Theme', 'last_name' => 'Bad',
            'email' => 'themebad@example.test', 'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/user/preferences', ['theme' => 'blue', 'language' => 'fr']);

        $resp->assertStatus(422)->assertJsonValidationErrors(['theme', 'language']);
    }

    public function test_login_without_password_returns_validation_error_not_crash()
    {
        $resp = $this->postJson('/api/login', ['email' => 'nobody@example.test']);
        $resp->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_user_can_update_price_per_km_and_currency()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Pref', 'last_name' => 'User',
            'email' => 'pref@example.test', 'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/user/preferences', ['price_per_km' => 0.75, 'currency' => 'USD']);

        $resp->assertStatus(200)
            ->assertJsonPath('price_per_km', 0.75)
            ->assertJsonPath('currency', 'USD');
    }

    public function test_update_preferences_rejects_invalid_currency_and_negative_price()
    {
        $register = $this->postJson('/api/register', [
            'first_name' => 'Pref', 'last_name' => 'Bad',
            'email' => 'prefbad@example.test', 'password' => 'Secret123!',
        ]);
        $token = $register->json('access_token');

        $resp = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/user/preferences', ['price_per_km' => -1, 'currency' => 'GBP']);

        $resp->assertStatus(422)->assertJsonValidationErrors(['price_per_km', 'currency']);
    }

    public function test_google_mobile_creates_plain_user()
    {
        Http::fake(["https://oauth2.googleapis.com/tokeninfo*" => Http::response([
            'email' => 'gdriver@example.test',
            'given_name' => 'GDriver',
            'family_name' => 'Test',
            'picture' => 'http://example.com/pic.png',
        ], 200)]);

        $gresp = $this->postJson('/api/auth/google/mobile', ['id_token' => 'fake-token']);
        $gresp->assertStatus(200)->assertJsonStructure(['access_token', 'user']);
        $user = $gresp->json('user');
        $this->assertEquals('gdriver@example.test', $user['email']);
        $this->assertEquals('user', $user['role']);
        $this->assertEquals(0.75, $user['price_per_km']);
    }
}
