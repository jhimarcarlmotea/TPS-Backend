<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Support\TpsCache;

class ClientProfile extends Model
{
    protected $fillable = [
        'user_id', 'slot_hold_id', 'booking_payment_id',
        'full_name', 'birthdate', 'email', 'mobile',
        'emergency_name', 'emergency_relation', 'emergency_phone',
    ];

    protected $casts = [
        'birthdate' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (): void {
            DB::afterCommit(static fn () => TpsCache::invalidate('appointments', 'dashboard'));
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }
}