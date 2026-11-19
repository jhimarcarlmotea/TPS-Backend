<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Support\TpsCache;

class ProviderAvailability extends Model
{
    protected $table = 'provider_availability';

    protected $fillable = ['provider_id', 'start_at', 'end_at', 'status', 'created_by_role'];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (): void {
            DB::afterCommit(static fn () => TpsCache::invalidate('schedule'));
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }
}