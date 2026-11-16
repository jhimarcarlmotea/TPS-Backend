<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('user_id')->nullable()->index();
            $t->string('actor_name', 150);
            $t->string('actor_role', 30)->default('SYSTEM')->index(); // ADMIN | AFFILIATE | CLIENT | SYSTEM
            $t->string('action', 60)->index(); 
            $t->string('category', 40)->default('system')->index(); // appointment | payment | schedule | account | service | system
            $t->string('title', 255);
            $t->text('description');
            $t->string('reference', 50)->nullable()->index();
            $t->json('metadata')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamps();

            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};

