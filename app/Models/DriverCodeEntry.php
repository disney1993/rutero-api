<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverCodeEntry extends Model
{
    use HasFactory;

    protected $fillable = ['owner_month_code_id', 'driver_id'];

    public function ownerMonthCode()
    {
        return $this->belongsTo(OwnerMonthCode::class, 'owner_month_code_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
