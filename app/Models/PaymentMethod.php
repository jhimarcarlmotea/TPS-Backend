<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\PublicStorageUrl;
use Illuminate\Support\Facades\DB;
use App\Support\TpsCache;

class PaymentMethod extends Model
{
    protected $fillable = [
        'name', 'letter', 'badge', 'account_name', 'account_number',
        'bank', 'qr_path', 'enabled', 'sort',
    ];

    protected $casts = ['enabled' => 'boolean'];

    protected static function booted(): void
    {
        $invalidate = static function (): void {
            DB::afterCommit(static fn () => TpsCache::invalidate('catalog'));
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public function toArrayPublic(): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'letter'         => $this->letter,
            'badge'          => $this->badge,
            'account_name'   => $this->account_name,
            'account_number' => $this->account_number,
            'bank'           => $this->bank,
            'qr_url'         => $this->qr_path ? PublicStorageUrl::fromPath((string) $this->qr_path) : null,
            'enabled'        => $this->enabled,
        ];
    }
}