<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AvatarPalette;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    // Debe coincidir con PASSWORD_REGEX en rutero-react/src/utils/validators.js:
    // mínimo 6 caracteres, al menos una mayúscula, un número y un símbolo.
    private const PASSWORD_REGEX = '/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/';

    private static function defaultPricePerKm(): float
    {
        return (float) config('rutero.default_price_per_km', 1.10);
    }

    // ==============================
    // Login con email y contraseña
    // ==============================
    public function register(Request $request)
    {
        $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\s]+$/u'],
            'last_name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\s]+$/u'],
            'second_last_name' => ['nullable', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\s]+$/u'],
            'avatar' => 'nullable|string',
            'avatar_color' => ['nullable', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'string', 'min:6', 'max:72', 'regex:' . self::PASSWORD_REGEX],
        ]);

        $fullName = trim(implode(' ', array_filter([
            $request->first_name,
            $request->last_name,
            $request->second_last_name,
        ])));

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'second_last_name' => $request->second_last_name,
            'name' => $fullName,
            'avatar' => $request->avatar,
            'avatar_color' => $request->avatar_color ?: AvatarPalette::random(),
            // Precio orientativo por defecto; el usuario lo puede cambiar luego en Ajustes.
            'price_per_km' => self::defaultPricePerKm(),
            'email'    => $request->email,
            'auth_provider' => 'password',
            'password' => Hash::make($request->password),
            'role'     => 'user',
        ]);

        $this->sendVerificationEmail($user);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user' => $user,
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer'
        ]);
    }

    public function userProfile(Request $request)
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\s]+$/u'],
            'last_name' => ['sometimes', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\s]+$/u'],
            'second_last_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\s]+$/u'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'current_password' => ['nullable', 'string', 'max:72'],
        ]);

        $emailChanging = array_key_exists('email', $data)
            && strcasecmp((string) $data['email'], (string) $user->email) !== 0;

        if ($emailChanging) {
            if ($user->isGoogleAccount()) {
                throw ValidationException::withMessages([
                    'email' => ['El email de una cuenta de Google no se puede cambiar aquí.'],
                ]);
            }

            if (! $request->filled('current_password')
                || ! Hash::check($request->input('current_password'), $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['La contraseña actual no es correcta'],
                ]);
            }
        }

        unset($data['current_password']);

        if (array_key_exists('first_name', $data) || array_key_exists('last_name', $data) || array_key_exists('second_last_name', $data)) {
            $firstName = $data['first_name'] ?? $user->first_name;
            $lastName = $data['last_name'] ?? $user->last_name;
            $secondLastName = array_key_exists('second_last_name', $data) ? $data['second_last_name'] : $user->second_last_name;
            $data['name'] = trim(implode(' ', array_filter([$firstName, $lastName, $secondLastName])));
        }

        $user->update($data);

        // El email nuevo hay que confirmarlo de nuevo.
        if ($emailChanging) {
            $user->forceFill(['email_verified_at' => null])->save();
            $this->sendVerificationEmail($user);
        }

        return response()->json($user);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        if ($user->isGoogleAccount()) {
            throw ValidationException::withMessages([
                'password' => ['Las cuentas de Google no gestionan la contraseña aquí.'],
            ]);
        }

        $request->validate([
            'current_password' => ['required', 'string', 'max:72'],
            'password' => ['required', 'string', 'min:6', 'max:72', 'confirmed', 'regex:' . self::PASSWORD_REGEX],
        ]);

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña actual no es correcta'], 422);
        }

        if (Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'La nueva contraseña debe ser distinta de la actual'], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Contraseña actualizada']);
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'price_per_km' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'currency' => ['sometimes', 'in:EUR,USD'],
            'theme' => ['sometimes', 'in:system,light,dark'],
            'language' => ['sometimes', 'in:es,en'],
            'work_mode' => ['sometimes', 'in:owner,driver'],
            'auto_complete_enabled' => ['sometimes', 'boolean'],
            'auto_complete_start_delay_min' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'auto_complete_margin_min' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'auto_complete_margin_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'auto_complete_default_duration_min' => ['sometimes', 'integer', 'min:5', 'max:1440'],
        ]);

        $user = $request->user();

        // Propietarios pueden probar modo conductor (y volver) sin exigir
        // has_driver_capability. Conductores puros deben ganar capacidad de
        // propietario (p. ej. añadir un vehículo) antes de cambiar.
        if (array_key_exists('work_mode', $data) && ! $user->hasOwnerCapability()) {
            return response()->json([
                'message' => 'Añade un vehículo para poder cambiar el modo de trabajo.',
            ], 422);
        }

        $user->update($data);
        $user->refresh();

        return response()->json($user);
    }

    public function updateAvatarColor(Request $request)
    {
        $request->validate([
            'avatar_color' => ['required', 'regex:/^#[A-Fa-f0-9]{6}$/'],
        ]);

        $user = $request->user();
        $user->avatar_color = $request->avatar_color;
        $user->save();

        return response()->json($user);
    }

    // ==============================
    // Login con Google
    // ==============================
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $firstName = $googleUser->user['given_name'] ?? null;
        $lastName = $googleUser->user['family_name'] ?? null;
        $avatar = $googleUser->getAvatar();
        $googleId = $googleUser->getId();
        $googleVerified = filter_var($googleUser->user['email_verified'] ?? $googleUser->user['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user = User::firstOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: $googleUser->getName(),
                'avatar' => $avatar,
                'avatar_color' => AvatarPalette::random(),
                'price_per_km' => self::defaultPricePerKm(),
                'auth_provider' => 'google',
                'google_id' => $googleId,
                'password' => Hash::make(uniqid()), // password random
            ]
        );

        if ($user->wasRecentlyCreated === false && blank($user->google_id) && $googleId) {
            $user->google_id = $googleId;
            $user->save();
        }

        if ($googleVerified && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
        ]);
    }

    // Mobile/SPA flow: accept id_token from client, verify with Google and create/find user
    public function mobileGoogle(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        $audiences = $this->googleAudiences();
        if ($audiences === []) {
            return response()->json(['message' => 'El inicio de sesión con Google no está configurado'], 503);
        }

        try {
            $resp = \Illuminate\Support\Facades\Http::get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $request->input('id_token')]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid id_token'], 401);
        }
        $data = $resp->ok() ? $resp->json() : null;

        // tokeninfo comprueba la firma y la caducidad. Falta comprobar que el
        // token se emitió para esta app (aud) y por Google (iss), y que el email
        // es de quien entra: sin eso valdría un token de Google de cualquier
        // otra app, o un email sin verificar, para entrar en la cuenta con ese email.
        if (! is_array($data)
            || ! in_array($data['aud'] ?? null, $audiences, true)
            || ! in_array($data['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            || (int) ($data['exp'] ?? 0) < time()
            || ! filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || empty($data['email'])) {
            return response()->json(['message' => 'Invalid id_token'], 401);
        }

        $email = $data['email'];
        $firstName = $data['given_name'] ?? null;
        $lastName = $data['family_name'] ?? null;
        $avatar = $data['picture'] ?? null;
        $googleId = $data['sub'] ?? null;
        // tokeninfo devuelve email_verified como "true"/"false" (texto).
        $googleVerified = filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: $email,
                'avatar' => $avatar,
                'avatar_color' => AvatarPalette::random(),
                'price_per_km' => self::defaultPricePerKm(),
                'auth_provider' => 'google',
                'google_id' => $googleId,
                'password' => Hash::make(uniqid()),
                'role' => 'user',
            ]
        );

        if ($user->wasRecentlyCreated === false && blank($user->google_id) && $googleId) {
            $user->google_id = $googleId;
            $user->save();
        }

        if ($googleVerified && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    // ==============================
    // Confirmación del email
    // ==============================

    /** Reenvía el correo de confirmación al usuario con sesión. */
    public function resendVerification(Request $request)
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Tu email ya está confirmado']);
        }

        $this->sendVerificationEmail($user);

        return response()->json(['message' => 'Te hemos enviado un correo para confirmar tu email'], 202);
    }

    /**
     * Enlace del correo (se abre en el navegador, sin sesión). La firma de la
     * URL (middleware signed) y el hash del email impiden confirmar otra cuenta
     * o un email que ya no es el de la cuenta.
     */
    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $user = User::find($id);
        $valid = $user && hash_equals(sha1($user->getEmailForVerification()), $hash);

        if ($valid && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return response()->view('auth.email-verified', ['valid' => $valid], $valid ? 200 : 403);
    }

    /** Client ID de Google aceptados como "aud": el web y los de Android/iOS. */
    private function googleAudiences(): array
    {
        $ids = [config('services.google.client_id'), ...explode(',', (string) config('services.google.mobile_client_ids'))];

        return array_values(array_filter(array_map(fn ($id) => trim((string) $id), $ids)));
    }

    /** Un fallo del servicio de correo no impide registrarse ni cambiar el email. */
    private function sendVerificationEmail(User $user): void
    {
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::warning('Verification email failed: '.$e->getMessage());
        }
    }
}
