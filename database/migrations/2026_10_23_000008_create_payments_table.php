<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('appointment_id');
            $table->decimal('amount', 10, 2);
            $table->string('gateway', 30);
            $table->string('gateway_ref', 100)->nullable()->unique();
            $table->string('method', 30)->nullable();
            $table->enum('status', ['PENDING', 'PAID', 'FAILED', 'REFUNDED'])->default('PENDING');
            $table->dateTime('paid_at')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();

            $table->foreign('appointment_id', 'fk_pay_appt')
                ->references('id')->on('appointments')->onDelete('restrict');
            $table->index('status', 'idx_pay_status');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_pay_amount CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
