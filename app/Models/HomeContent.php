<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Support\TpsCache;

class HomeContent extends Model
{
    protected $fillable = ['key', 'content', 'updated_by'];
    protected $casts = ['content' => 'array'];

    protected static function booted(): void
    {
        $invalidate = static function (): void {
            DB::afterCommit(static fn () => TpsCache::invalidate('website'));
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }
}
