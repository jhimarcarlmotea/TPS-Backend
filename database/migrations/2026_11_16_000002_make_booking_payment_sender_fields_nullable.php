<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payments', function (Blueprint $table) {
            $table->string('wallet_number', 20)->nullable()->change();
            $table->string('reference_number', 60)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('booking_payments')->whereNull('wallet_number')->update(['wallet_number' => '']);
        DB::table('booking_payments')->whereNull('reference_number')->update(['reference_number' => '']);

        Schema::table('booking_payments', function (Blueprint $table) {
            $table->string('wallet_number', 20)->nullable(false)->change();
            $table->string('reference_number', 60)->nullable(false)->change();
        });
    }
};
