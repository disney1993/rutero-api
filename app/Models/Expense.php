<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
 * TODO(rutero): foto del ticket o factura del gasto. Subirla a un
 *   almacenamiento persistente (S3 o similar; el disco de Laravel Cloud no
 *   lo es) y guardar aquí su ruta; en la app, ver ExpenseFormModal.
 */
/** Gasto del negocio de un propietario. */
class Expense extends Model
{
    use SoftDeletes;

    public const PAYMENT_METHODS = ['efectivo', 'tarjeta', 'bizum', 'transferencia', 'domiciliacion'];

    protected $fillable = [
        'owner_id', 'date', 'category', 'description', 'amount', 'vat_amount',
        'deductible', 'recurring_monthly', 'recurring_parent_id',
        'vehicle_id', 'driver_id', 'payment_method', 'notes',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'deductible' => 'boolean',
        'recurring_monthly' => 'boolean',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public static function categories(): array
    {
        return config('expense_categories');
    }
}
