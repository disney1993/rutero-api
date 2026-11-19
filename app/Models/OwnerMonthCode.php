<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OwnerMonthCode extends Model
{
    use HasFactory;

    public const TYPE_MONTH = 'month';

    public const TYPE_DAY = 'day';

    protected $fillable = ['owner_id', 'code', 'month', 'type', 'valid_on', 'scope_key'];

    protected $casts = [
        'valid_on' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $code) {
            $code->scope_key = $code->resolveScopeKey();
        });
    }

    /** Non-null key for uniqueness: valid_on for day codes, month for month codes. */
    public function resolveScopeKey(): string
    {
        if ($this->isDay()) {
            $validOn = $this->valid_on;
            if ($validOn instanceof \DateTimeInterface) {
                return $validOn->format('Y-m-d');
            }

            return substr((string) $validOn, 0, 10);
        }

        return (string) $this->month;
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function driverEntries()
    {
        return $this->hasMany(DriverCodeEntry::class, 'owner_month_code_id');
    }

    public function isDay(): bool
    {
        return $this->type === self::TYPE_DAY;
    }

    /** ¿El código autoriza unirse / operar en la fecha dada (Y-m-d)? */
    public function authorizesDate(?string $date = null): bool
    {
        $date = substr($date ?: now()->toDateString(), 0, 10);

        if ($this->isDay()) {
            return $this->valid_on && $this->valid_on->toDateString() === $date;
        }

        return $this->month === substr($date, 0, 7);
    }
}
