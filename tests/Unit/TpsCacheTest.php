<?php

namespace Tests\Unit;

use App\Support\TpsCache;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TpsCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::store()->flush();
    }

    public function test_it_reuses_values_until_their_cache_group_is_invalidated(): void
    {
        $loads = 0;
        $loader = function () use (&$loads) {
            $loads++;

            return 'current';
        };

        $this->assertSame('current', TpsCache::remember('catalog', 'services', 60, $loader));
        $this->assertSame('current', TpsCache::remember('catalog', 'services', 60, $loader));
        $this->assertSame(1, $loads);

        TpsCache::invalidate('catalog');

        $this->assertSame('current', TpsCache::remember('catalog', 'services', 60, $loader));
        $this->assertSame(2, $loads);
    }

    public function test_invalidating_a_group_does_not_invalidate_other_groups(): void
    {
        $loads = 0;
        $loader = function () use (&$loads) {
            return ++$loads;
        };

        $this->assertSame(1, TpsCache::remember('directory', 'psychologists', 60, $loader));
        $this->assertSame(2, TpsCache::remember('website', 'home', 60, $loader));

        TpsCache::invalidate('directory');

        $this->assertSame(2, TpsCache::remember('website', 'home', 60, $loader));
        $this->assertSame(3, TpsCache::remember('directory', 'psychologists', 60, $loader));
    }

    public function test_distinct_viewer_identities_use_separate_entries(): void
    {
        $this->assertSame(
            'admin-one',
            TpsCache::remember('appointments', 'admin:1', 60, fn () => 'admin-one'),
        );
        $this->assertSame(
            'admin-two',
            TpsCache::remember('appointments', 'admin:2', 60, fn () => 'admin-two'),
        );
    }

    public function test_user_changes_invalidate_cached_directory_data_after_commit(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->nullable();
            $table->string('avatar_path')->nullable();
            $table->text('background')->nullable();
            $table->timestamps();
        });

        $loads = 0;
        $loader = function () use (&$loads) {
            return ++$loads;
        };

        try {
            $this->assertSame(1, TpsCache::remember('directory', 'psychologists:active', 60, $loader));

            DB::transaction(function () {
                User::create([
                    'first_name' => 'Ari',
                    'last_name' => 'Test',
                    'email' => 'ari@example.test',
                    'role' => 'AFFILIATE',
                    'is_active' => true,
                ]);

                $this->assertSame(1, TpsCache::remember(
                    'directory',
                    'psychologists:active',
                    60,
                    fn () => 2,
                ));
            });

            $this->assertSame(2, TpsCache::remember('directory', 'psychologists:active', 60, $loader));
        } finally {
            Schema::dropIfExists('users');
        }
    }
}
