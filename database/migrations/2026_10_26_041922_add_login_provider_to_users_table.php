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
            $table->enum('login_provider', ['SYSTEM', 'GOOGLE', 'FACEBOOK'])
                ->default('SYSTEM')
                ->after('password');
        });

        // Fill in the accounts that already exist
        DB::table('users')->whereNotNull('facebook_id')->update(['login_provider' => 'FACEBOOK']);
        DB::table('users')->whereNotNull('google_id')->update(['login_provider' => 'GOOGLE']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('login_provider');
        });
    }
};