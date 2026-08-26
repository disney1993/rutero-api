<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OwnerMonthCode extends Model
{
    use HasFactory;

    protected $fillable = ['owner_id', 'code', 'month'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function driverEntries()
    {
        return $this->hasMany(DriverCodeEntry::class, 'owner_month_code_id');
    }
}
