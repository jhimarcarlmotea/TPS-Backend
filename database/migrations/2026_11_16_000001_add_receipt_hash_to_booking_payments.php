<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payments', function (Blueprint $table) {
            // SHA-256 fingerprint of the uploaded receipt image (hex: 64 chars)
            $table->string('receipt_hash', 64)->nullable()->after('receipt_path');
        });
    }

    public function down(): void
    {
        Schema::table('booking_payments', function (Blueprint $table) {
            $table->dropColumn('receipt_hash');
        });
    }
};

