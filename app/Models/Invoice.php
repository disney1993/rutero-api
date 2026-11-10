<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Factura emitida. Es inalterable: no hay edición ni borrado; un error se
 * corrige con una factura rectificativa (type = corrective) que la anula.
 */
class Invoice extends Model
{
    public const TYPES = ['simplified', 'full', 'corrective'];

    protected $fillable = [
        'owner_id', 'ruta_id', 'series', 'number', 'full_number', 'type', 'issue_date', 'operation_date',
        'issuer', 'recipient', 'concept', 'base', 'vat_rate', 'vat_amount', 'total', 'exemption_reason',
        'payment_method', 'corrects_invoice_id', 'correction_reason', 'hash', 'previous_hash',
    ];

    protected $casts = [
        'issuer' => 'array',
        'recipient' => 'array',
        'issue_date' => 'date:Y-m-d',
        'operation_date' => 'date:Y-m-d',
        'base' => 'float',
        'vat_rate' => 'float',
        'vat_amount' => 'float',
        'total' => 'float',
        'number' => 'integer',
    ];

    protected $hidden = ['previous_hash'];

    public function ruta()
    {
        return $this->belongsTo(Ruta::class)->withTrashed();
    }

    public function corrects()
    {
        return $this->belongsTo(self::class, 'corrects_invoice_id');
    }

    public function correctedBy()
    {
        return $this->hasOne(self::class, 'corrects_invoice_id');
    }

    /** Factura vigente: no es rectificativa ni ha sido rectificada. */
    public function scopeCurrent($query)
    {
        return $query->where('type', '!=', 'corrective')->whereDoesntHave('correctedBy');
    }

    /**
     * Huella encadenada (preparación para VeriFactu): NIF del emisor, número,
     * fecha de expedición, importe total y huella de la factura anterior.
     */
    public static function computeHash(string $taxId, string $fullNumber, string $issueDate, float $total, ?string $previous): string
    {
        return hash('sha256', implode('|', [$taxId, $fullNumber, $issueDate, number_format($total, 2, '.', ''), $previous ?? '']));
    }
}
