<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'login_provider')) {
                $table->enum('login_provider', ['SYSTEM', 'GOOGLE', 'FACEBOOK'])
                    ->default('SYSTEM')
                    ->after('password');
            }

            if (! Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->unique()->after('email_verified_at');
            }

            if (! Schema::hasColumn('users', 'facebook_id')) {
                $table->string('facebook_id')->nullable()->unique()->after('google_id');
            }

            if (! Schema::hasColumn('users', 'verification_code')) {
                $table->string('verification_code')->nullable()->after('facebook_id');
            }

            if (! Schema::hasColumn('users', 'verification_expires_at')) {
                $table->timestamp('verification_expires_at')->nullable()->after('verification_code');
            }

            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path', 255)->nullable()->after('phone');
            }
        });

        DB::table('users')
            ->whereNull('login_provider')
            ->whereNotNull('google_id')
            ->update(['login_provider' => 'GOOGLE']);

        DB::table('users')
            ->whereNull('login_provider')
            ->whereNotNull('facebook_id')
            ->update(['login_provider' => 'FACEBOOK']);

        DB::table('users')
            ->whereNull('login_provider')
            ->update(['login_provider' => 'SYSTEM']);

        DB::table('users')
            ->where('login_provider', 'SYSTEM')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'login_provider')) {
                $table->dropColumn('login_provider');
            }

            foreach (['email_verified_at', 'google_id', 'facebook_id', 'verification_code', 'verification_expires_at', 'avatar_path'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
