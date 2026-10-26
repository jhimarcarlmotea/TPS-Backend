<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable();
            $table->string('google_id')->nullable()->unique();
            $table->string('facebook_id')->nullable()->unique();
            $table->string('verification_code')->nullable();
            $table->timestamp('verification_expires_at')->nullable();
        });

        // Existing accounts (admin, etc.) stay usable: mark them as verified.
        DB::table('users')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropUnique(['facebook_id']);
            $table->dropColumn([
                'email_verified_at',
                'google_id',
                'facebook_id',
                'verification_code',
                'verification_expires_at',
            ]);
        });
    }
};