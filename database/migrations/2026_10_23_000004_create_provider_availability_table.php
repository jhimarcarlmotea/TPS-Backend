<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_availability', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('provider_id');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();

            $table->foreign('provider_id', 'fk_avail_provider')
                ->references('id')->on('providers')->onDelete('cascade');
            $table->index(['provider_id', 'start_at', 'end_at'], 'idx_avail_provider_time');
        });

        DB::statement('ALTER TABLE provider_availability ADD CONSTRAINT chk_avail_range CHECK (end_at > start_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_availability');
    }
};
