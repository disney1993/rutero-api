<?php

namespace App\Http\Controllers;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Support\SpanishTaxId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Datos de facturación del propietario (Ajustes → Datos de facturación).
 * Sin ellos completos y válidos no se puede emitir ninguna factura.
 */
class BillingProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = BillingProfile::firstOrNew(['user_id' => $user->id], [
            'series' => now()->format('Y').'-',
            'activity' => 'passengers',
            'vat_rate' => 10,
            'yearly_series' => true,
        ]);

        return response()->json($this->present($profile));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasOwnerCapability()) {
            return response()->json(['message' => 'Necesitas al menos un vehículo para facturar'], 403);
        }
        $profile = BillingProfile::firstOrNew(['user_id' => $user->id]);

        $data = $request->validate([
            'issuer_type' => ['required', Rule::in(['autonomo', 'sociedad'])],
            'legal_name' => ['required', 'string', 'min:2', 'max:120'],
            'tax_id' => ['required', 'string', 'max:15', function ($attr, $value, $fail) use ($request) {
                $v = SpanishTaxId::normalize($value);
                if (! SpanishTaxId::isValid($v)) {
                    $fail('El NIF no es válido: revisa la letra o el dígito de control.');
                } elseif ($request->input('issuer_type') === 'autonomo' && ! (SpanishTaxId::isDni($v) || SpanishTaxId::isNie($v))) {
                    $fail('Un autónomo factura con su DNI o NIE.');
                } elseif ($request->input('issuer_type') === 'sociedad' && ! SpanishTaxId::isCif($v)) {
                    $fail('Una sociedad factura con su NIF de entidad (CIF).');
                }
            }],
            'address_line' => ['required', 'string', 'min:3', 'max:150'],
            'postal_code' => ['required', 'string', function ($attr, $value, $fail) {
                if (! SpanishTaxId::isValidPostalCode($value)) {
                    $fail('El código postal debe tener 5 cifras y corresponder a una provincia (01-52).');
                }
            }],
            'city' => ['required', 'string', 'min:2', 'max:80'],
            'province' => ['required', Rule::in(BillingProfile::PROVINCES)],
            'activity' => ['required', Rule::in(BillingProfile::ACTIVITIES)],
            'vat_rate' => ['required', 'numeric', Rule::in(BillingProfile::VAT_RATES)],
            'exemption_reason' => ['nullable', 'required_if:vat_rate,0', 'string', 'max:200'],
            'series' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-\/]+$/'],
            'yearly_series' => ['sometimes', 'boolean'],
            'next_number' => ['sometimes', 'integer', 'min:1', 'max:999999'],
            'licence' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:120'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'iban' => ['nullable', 'string', 'max:40', function ($attr, $value, $fail) {
                if (filled($value) && ! SpanishTaxId::isValidIban($value)) {
                    $fail('El IBAN no es válido: revisa los dígitos de control.');
                }
            }],
        ], [
            'exemption_reason.required_if' => 'Indica el motivo de la exención de IVA (artículo de la Ley 37/1992).',
            'series.regex' => 'La serie solo puede tener letras, números, guiones y barras.',
        ]);

        $data['tax_id'] = SpanishTaxId::normalize($data['tax_id']);
        $data['iban'] = filled($data['iban'] ?? null) ? SpanishTaxId::normalize($data['iban']) : null;
        if ((float) $data['vat_rate'] !== 0.0) {
            $data['exemption_reason'] = null;
        }

        // La numeración no se puede cambiar una vez emitida una factura de esa serie.
        $issuedInSeries = Invoice::where('owner_id', $user->id)->where('series', $data['series'])->exists();
        if (array_key_exists('next_number', $data) && $issuedInSeries && (int) $data['next_number'] !== $profile->next_number) {
            return response()->json(['message' => 'Ya has emitido facturas en esta serie: la numeración debe seguir siendo correlativa.', 'errors' => ['next_number' => ['La numeración debe seguir siendo correlativa.']]], 422);
        }
        if ($issuedInSeries) {
            unset($data['next_number']);
        } elseif (! array_key_exists('next_number', $data) && $profile->series !== $data['series']) {
            $data['next_number'] = 1; // serie nueva: empieza en 1
        }

        $profile->fill($data);
        $profile->user_id = $user->id;
        $profile->save();

        return response()->json($this->present($profile->fresh()));
    }

    /** Logotipo para las facturas: data URI PNG o JPEG de hasta 300 KB. */
    public function updateLogo(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasOwnerCapability()) {
            return response()->json(['message' => 'Necesitas al menos un vehículo para facturar'], 403);
        }
        $data = $request->validate(['logo' => ['required', 'string', 'max:500000']]);

        if (! preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#', $data['logo'], $m)) {
            return $this->logoError('El logotipo debe ser una imagen PNG o JPG.');
        }
        $binary = base64_decode($m[2], true);
        if ($binary === false || strlen($binary) > BillingProfile::LOGO_MAX_BYTES) {
            return $this->logoError('El logotipo no puede superar los 300 KB.');
        }
        $info = @getimagesizefromstring($binary);
        if (! $info || ! in_array($info['mime'] ?? '', ['image/png', 'image/jpeg'], true)) {
            return $this->logoError('El archivo no es una imagen válida.');
        }

        $profile = BillingProfile::firstOrNew(['user_id' => $user->id]);
        $profile->logo = $data['logo'];
        $profile->save();

        return response()->json($this->present($profile->fresh()));
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasOwnerCapability()) {
            return response()->json(['message' => 'Necesitas al menos un vehículo para facturar'], 403);
        }
        $profile = BillingProfile::where('user_id', $user->id)->first();
        if ($profile) {
            $profile->update(['logo' => null]);
        }

        return response()->json($this->present($profile ?? new BillingProfile));
    }

    private function logoError(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['logo' => [$message]]], 422);
    }

    private function present(BillingProfile $profile): array
    {
        $data = $profile->only([
            'issuer_type', 'legal_name', 'tax_id', 'address_line', 'postal_code', 'city', 'province',
            'activity', 'vat_rate', 'exemption_reason', 'series', 'yearly_series', 'next_number', 'licence', 'email', 'phone', 'iban', 'logo',
        ]);
        // Sin fila en BD (o migración antigua) se asume serie anual activada.
        $data['yearly_series'] = (bool) ($data['yearly_series'] ?? true);

        return [
            'profile' => $data,
            'ready' => $profile->exists && $profile->isReady(),
            'missing' => $profile->exists ? $profile->missing() : BillingProfile::REQUIRED,
            'simplified_limit' => $profile->simplifiedLimit(),
            'provinces' => BillingProfile::PROVINCES,
            'vat_rates' => BillingProfile::VAT_RATES,
        ];
    }
}
