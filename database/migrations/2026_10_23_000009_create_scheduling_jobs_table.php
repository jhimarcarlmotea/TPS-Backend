<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('job_id', 40)->unique();
            $table->string('schema_version', 10)->default('1.0');
            $table->unsignedInteger('request_id');
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('triggered_by')->nullable();
            $table->enum('job_status', ['QUEUED', 'RUNNING', 'DONE', 'FAILED'])->default('QUEUED');
            $table->enum('result_status', [
                'RECOMMENDATION_FOUND', 'NO_ALTERNATIVE', 'INVALID', 'NO_REQUEST', 'INVALID_REQUEST'
            ])->nullable();
            $table->boolean('conflict_detected')->nullable();
            $table->decimal('z_star', 8, 2)->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->unsignedInteger('candidate_count')->nullable();
            $table->dateTime('t_run')->useCurrent();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->dateTime('completed_at')->nullable();

            $table->foreign('request_id', 'fk_job_request')
                ->references('id')->on('appointment_requests')->onDelete('cascade');
            $table->foreign('provider_id', 'fk_job_provider')
                ->references('id')->on('providers')->onDelete('restrict');
            $table->foreign('triggered_by', 'fk_job_user')
                ->references('id')->on('users')->onDelete('set null');
            $table->index('request_id', 'idx_job_request');
            $table->index('job_status', 'idx_job_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_jobs');
    }
};
