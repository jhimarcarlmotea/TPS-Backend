<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Support\TpsCache;

class Provider extends Model
{
    protected $table = 'providers';

    protected $fillable = ['user_id', 'code', 'display_name', 'is_active'];

    protected static function booted(): void
    {
        $invalidate = static function (): void {
            DB::afterCommit(static fn () => TpsCache::invalidate('appointments', 'dashboard', 'schedule'));
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }
}