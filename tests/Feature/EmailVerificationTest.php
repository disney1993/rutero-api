<?php

namespace Tests\Feature;

use App\Models\OwnerDriver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this;
    }

    private function verifyUrl(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => $hash ?? sha1($user->getEmailForVerification()),
        ]);
    }

    public function test_registering_sends_the_confirmation_email_and_the_account_starts_unverified(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'first_name' => 'Nuevo', 'last_name' => 'Conductor',
            'email' => 'nuevo@example.test', 'password' => 'Secret123!',
        ])->assertOk()->assertJsonPath('user.email_verified_at', null);

        Notification::assertSentTo(User::where('email', 'nuevo@example.test')->first(), VerifyEmail::class);
    }

    public function test_the_signed_link_confirms_the_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->verifyUrl($user))->assertOk()->assertSee('Email confirmado');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_tampered_or_unsigned_link_confirms_nothing(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->verifyUrl($user, sha1('otro@example.test')))->assertForbidden();
        $this->get("/email/verify/{$user->id}/".sha1($user->email))->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_someone_who_registers_with_another_persons_email_cannot_take_their_invitation(): void
    {
        $owner = User::factory()->create();
        Vehicle::factory()->create(['owner_id' => $owner->id]);
        $this->as($owner)->postJson('/api/team/invitations', ['type' => 'email', 'email' => 'juan@example.test'])->assertStatus(202);
        $invitation = OwnerDriver::first();

        // Se registra antes que Juan con su email, pero no puede confirmarlo.
        $squatter = User::factory()->unverified()->create(['email' => 'juan@example.test']);
        $this->as($squatter)->getJson('/api/me/invitations')->assertOk()->assertJsonCount(0);
        $this->as($squatter)->postJson("/api/me/invitations/{$invitation->id}/accept")->assertNotFound();
        $this->assertSame(OwnerDriver::STATUS_PENDING, $invitation->fresh()->status);

        // Quien sí recibe el correo lo confirma y ya ve la invitación.
        $this->get($this->verifyUrl($squatter))->assertOk();
        $this->as($squatter->fresh())->getJson('/api/me/invitations')->assertOk()->assertJsonCount(1);
    }

    public function test_changing_the_email_requires_confirming_it_again(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => 'Secret123!']);

        $this->as($user)->patchJson('/api/user', [
            'email' => 'nuevo-email@example.test', 'current_password' => 'Secret123!',
        ])->assertOk();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resending_the_confirmation_email(): void
    {
        Notification::fake();
        $unverified = User::factory()->unverified()->create();
        $verified = User::factory()->create();

        $this->as($unverified)->postJson('/api/email/verification-notification')->assertStatus(202);
        $this->as($verified)->postJson('/api/email/verification-notification')->assertOk();

        Notification::assertSentTo($unverified, VerifyEmail::class);
        Notification::assertNotSentTo($verified, VerifyEmail::class);
    }

    public function test_google_login_confirms_the_email(): void
    {
        $this->fakeGoogleToken(['email' => 'g1@example.test']);

        $this->postJson('/api/auth/google/mobile', ['id_token' => 'token-1'])->assertOk();

        $this->assertTrue(User::where('email', 'g1@example.test')->first()->hasVerifiedEmail());
    }

    public function test_google_login_confirms_an_existing_unverified_password_account(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'mixto@example.test']);
        $this->fakeGoogleToken(['email' => 'mixto@example.test']);

        $this->postJson('/api/auth/google/mobile', ['id_token' => 'token'])->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }
}
