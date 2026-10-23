<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_recommendations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('job_id');
            $table->unsignedInteger('request_id');
            $table->unsignedInteger('provider_id');
            $table->dateTime('recommended_start');
            $table->dateTime('recommended_end');
            $table->decimal('scheduling_value', 6, 2)->nullable();
            $table->unsignedInteger('rank_order')->default(1);
            $table->boolean('was_chosen')->default(false);
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->foreign('job_id', 'fk_rec_job')
                ->references('id')->on('scheduling_jobs')->onDelete('cascade');
            $table->foreign('request_id', 'fk_rec_request')
                ->references('id')->on('appointment_requests')->onDelete('cascade');
            $table->foreign('provider_id', 'fk_rec_provider')
                ->references('id')->on('providers')->onDelete('restrict');
            $table->index('request_id', 'idx_rec_request');
        });

        DB::statement('ALTER TABLE scheduling_recommendations ADD CONSTRAINT chk_rec_range CHECK (recommended_end > recommended_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_recommendations');
    }
};
