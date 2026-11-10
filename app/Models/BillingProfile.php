<?php

namespace App\Models;

use App\Support\SpanishTaxId;
use Illuminate\Database\Eloquent\Model;

/**
 * Datos fiscales del emisor de las facturas (el propietario). Se guardan una
 * vez y se copian en cada factura al emitirla.
 */
class BillingProfile extends Model
{
    public const ACTIVITIES = ['passengers', 'goods'];

    /** Importe máximo (IVA incluido) de la factura simplificada (RD 1619/2012, art. 4). */
    public const SIMPLIFIED_LIMIT = ['passengers' => 3000, 'goods' => 400];

    public const VAT_RATES = [0, 4, 10, 21];

    /** Provincias para la lista cerrada del domicilio fiscal. */
    public const PROVINCES = [
        'Álava', 'Albacete', 'Alicante', 'Almería', 'Asturias', 'Ávila', 'Badajoz', 'Barcelona', 'Burgos', 'Cáceres',
        'Cádiz', 'Cantabria', 'Castellón', 'Ceuta', 'Ciudad Real', 'Córdoba', 'Cuenca', 'Girona', 'Granada', 'Guadalajara',
        'Gipuzkoa', 'Huelva', 'Huesca', 'Illes Balears', 'Jaén', 'A Coruña', 'La Rioja', 'Las Palmas', 'León', 'Lleida',
        'Lugo', 'Madrid', 'Málaga', 'Melilla', 'Murcia', 'Navarra', 'Ourense', 'Palencia', 'Pontevedra', 'Salamanca',
        'Santa Cruz de Tenerife', 'Segovia', 'Sevilla', 'Soria', 'Tarragona', 'Teruel', 'Toledo', 'Valencia', 'Valladolid',
        'Bizkaia', 'Zamora', 'Zaragoza',
    ];

    /** Campos obligatorios para poder emitir facturas. */
    public const REQUIRED = ['legal_name', 'tax_id', 'address_line', 'postal_code', 'city', 'province', 'series'];

    protected $fillable = [
        'user_id', 'issuer_type', 'legal_name', 'tax_id', 'address_line', 'postal_code', 'city', 'province',
        'activity', 'vat_rate', 'exemption_reason', 'series', 'next_number', 'next_corrective_number',
        'licence', 'email', 'phone', 'iban',
    ];

    protected $casts = [
        'vat_rate' => 'float',
        'next_number' => 'integer',
        'next_corrective_number' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Campos obligatorios que faltan o no son válidos. */
    public function missing(): array
    {
        $missing = [];
        foreach (self::REQUIRED as $field) {
            if (blank($this->{$field})) {
                $missing[] = $field;
            }
        }
        if (! in_array('tax_id', $missing, true) && ! SpanishTaxId::isValid($this->tax_id)) {
            $missing[] = 'tax_id';
        }
        if (! in_array('postal_code', $missing, true) && ! SpanishTaxId::isValidPostalCode($this->postal_code)) {
            $missing[] = 'postal_code';
        }
        if ((float) $this->vat_rate === 0.0 && blank($this->exemption_reason)) {
            $missing[] = 'exemption_reason';
        }

        return $missing;
    }

    public function isReady(): bool
    {
        return $this->missing() === [];
    }

    public function simplifiedLimit(): int
    {
        return self::SIMPLIFIED_LIMIT[$this->activity] ?? 400;
    }

    /** Copia de los datos del emisor que se guarda en la factura. */
    public function snapshot(): array
    {
        return [
            'issuer_type' => $this->issuer_type,
            'legal_name' => $this->legal_name,
            'tax_id' => SpanishTaxId::normalize($this->tax_id),
            'address_line' => $this->address_line,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'province' => $this->province,
            'licence' => $this->licence,
            'email' => $this->email,
            'phone' => $this->phone,
            'iban' => $this->iban ? SpanishTaxId::normalize($this->iban) : null,
        ];
    }
}
