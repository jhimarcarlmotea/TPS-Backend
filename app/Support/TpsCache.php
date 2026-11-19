<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class TpsCache
{
    private const VERSION_TTL_SECONDS = 315360000;

    public static function remember(string $group, string $identity, int $ttlSeconds, Closure $callback): mixed
    {
        $cache = self::repository();
        $version = $cache->get(self::versionKey($group), 0);
        $key = 'tps:' . $group . ':v' . $version . ':' . hash('sha256', $identity);

        return $cache->remember($key, $ttlSeconds, $callback);
    }

    public static function invalidate(string ...$groups): void
    {
        foreach (array_unique($groups) as $group) {
            $cache = self::repository();
            $key = self::versionKey($group);
            $cache->add($key, 0, self::VERSION_TTL_SECONDS);
            $cache->increment($key);
        }
    }

    private static function repository(): Repository
    {
        return app()->environment('testing') ? Cache::store() : Cache::store('redis');
    }

    private static function versionKey(string $group): string
    {
        return 'tps:cache-version:' . $group;
    }
}
