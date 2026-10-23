<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('request_id')->unique();
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('service_id');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->enum('status', ['ACCEPTED', 'CONFIRMED', 'COMPLETED', 'CANCELLED'])
                ->default('ACCEPTED');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();

            $table->foreign('request_id', 'fk_appt_request')
                ->references('id')->on('appointment_requests')->onDelete('restrict');
            $table->foreign('client_id', 'fk_appt_client')
                ->references('id')->on('users')->onDelete('restrict');
            $table->foreign('provider_id', 'fk_appt_provider')
                ->references('id')->on('providers')->onDelete('restrict');
            $table->foreign('service_id', 'fk_appt_service')
                ->references('id')->on('services')->onDelete('restrict');
            $table->index(['provider_id', 'status', 'start_at', 'end_at'], 'idx_appt_provider_time');
        });

        DB::statement('ALTER TABLE appointments ADD CONSTRAINT chk_appt_range CHECK (end_at > start_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
