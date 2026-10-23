<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->string('request_code', 30)->unique();
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('service_id');
            $table->dateTime('requested_start');
            $table->dateTime('requested_end');
            $table->dateTime('submitted_at')->useCurrent();
            $table->enum('status', ['PENDING', 'ACCEPTED', 'REJECTED', 'CONFIRMED', 'CANCELLED', 'COMPLETED'])
                ->default('PENDING');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();

            $table->foreign('client_id', 'fk_req_client')
                ->references('id')->on('users')->onDelete('restrict');
            $table->foreign('provider_id', 'fk_req_provider')
                ->references('id')->on('providers')->onDelete('restrict');
            $table->foreign('service_id', 'fk_req_service')
                ->references('id')->on('services')->onDelete('restrict');

            $table->unique(
                ['client_id', 'provider_id', 'service_id', 'requested_start'],
                'uq_req_duplicate'
            );
            $table->index(['provider_id', 'status', 'requested_start'], 'idx_req_provider_status');
            $table->index(['provider_id', 'requested_end'], 'idx_req_end');
        });

        DB::statement('ALTER TABLE appointment_requests ADD CONSTRAINT chk_req_range CHECK (requested_end > requested_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_requests');
    }
};
