<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_status_history', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('request_id');
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->unsignedInteger('changed_by')->nullable();
            $table->string('remarks', 255)->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->foreign('request_id', 'fk_hist_request')
                ->references('id')->on('appointment_requests')->onDelete('cascade');
            $table->foreign('changed_by', 'fk_hist_user')
                ->references('id')->on('users')->onDelete('set null');
            $table->index(['request_id', 'created_at'], 'idx_hist_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_status_history');
    }
};
