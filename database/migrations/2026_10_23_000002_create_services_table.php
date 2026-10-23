<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->unsignedInteger('duration_minutes');
            $table->decimal('price', 10, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
        });

        DB::statement('ALTER TABLE services ADD CONSTRAINT chk_service_duration CHECK (duration_minutes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
