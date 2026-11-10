<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Destinatario de facturas guardado para reutilizarlo. */
class InvoiceClient extends Model
{
    protected $fillable = ['owner_id', 'name', 'tax_id', 'address_line', 'postal_code', 'city', 'province', 'email'];
}
