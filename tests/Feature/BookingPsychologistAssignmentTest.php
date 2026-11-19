<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchedulingEngineService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingPsychologistAssignmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('slot_holds');
        Schema::dropIfExists('booking_payments');
        Schema::dropIfExists('provider_availability');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('services');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->string('avatar_path')->nullable();
            $table->timestamps();
        });
        Schema::create('providers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('code')->nullable();
            $table->string('display_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('provider_availability', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('provider_id');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('provider_id');
            $table->string('service_id');
            $table->string('status');
            $table->string('approval_status');
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
        });
        Schema::create('slot_holds', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('user_id');
            $table->string('service_id');
            $table->unsignedInteger('assessment_type_id')->nullable();
            $table->string('assessment_type_name')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->dateTime('expires_at');
            $table->timestamps();
        });
        Schema::create('services', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug')->unique();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('duration_minutes')->default(60);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('slot_holds');
        Schema::dropIfExists('booking_payments');
        Schema::dropIfExists('provider_availability');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('services');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_admin_selected_for_pc_is_assigned_to_least_booked_available_admin(): void
    {
        $date = now()->addDays(3)->toDateString();
        $client = $this->user('CLIENT');
        $selectedAdmin = $this->user('ADMIN');
        $busierAdmin = $this->user('ADMIN');
        $this->provider($selectedAdmin, 1, $date);
        $this->provider($busierAdmin, 2, $date);
        DB::table('services')->insert([
            'slug' => 'psychotherapy',
            'name' => 'Psychotherapy',
            'enabled' => true,
            'duration_minutes' => 60,
        ]);
        foreach ([
            [$date . ' 09:00:00', $date . ' 10:00:00'],
            [$date . ' 11:00:00', $date . ' 12:00:00'],
        ] as [$start, $end]) {
            DB::table('booking_payments')->insert([
                'provider_id' => 1,
                'service_id' => 'aba',
                'status' => 'verified',
                'approval_status' => 'approved',
                'start_at' => $start,
                'end_at' => $end,
            ]);
        }

        $this->mock(SchedulingEngineService::class)
            ->shouldReceive('solve')
            ->once()
            ->andReturn(['job_status' => 'COMPLETED', 'decision' => 'FEASIBLE']);

        $response = $this->actingAs($client)->postJson('/api/booking/hold', [
            'service' => 'psychotherapy',
            'psychologist_id' => $selectedAdmin->id,
            'provider_id' => 1,
            'date' => $date,
            'start_time' => '10:00',
        ]);

        $response->assertCreated();
        $this->assertSame(2, $response->json('hold.provider_id'));
    }

    public function test_pc_booking_keeps_the_selected_affiliate(): void
    {
        $date = now()->addDays(3)->toDateString();
        $client = $this->user('CLIENT');
        $affiliate = $this->user('AFFILIATE');
        $this->provider($affiliate, 3, $date);
        DB::table('services')->insert([
            'slug' => 'psychotherapy',
            'name' => 'Psychotherapy',
            'enabled' => true,
            'duration_minutes' => 60,
        ]);

        $this->mock(SchedulingEngineService::class)
            ->shouldReceive('solve')
            ->once()
            ->andReturn(['job_status' => 'COMPLETED', 'decision' => 'FEASIBLE']);

        $response = $this->actingAs($client)->postJson('/api/booking/hold', [
            'service' => 'psychotherapy',
            'psychologist_id' => $affiliate->id,
            'date' => $date,
            'start_time' => '10:00',
        ]);

        $response->assertCreated();
        $this->assertSame(3, $response->json('hold.provider_id'));
    }

    public function test_admin_without_a_matching_schedule_is_excluded_before_workload_ranking(): void
    {
        $date = now()->addDays(3)->toDateString();
        $client = $this->user('CLIENT');
        $availableAdmin = $this->user('ADMIN');
        $otherAdmin = $this->user('ADMIN');
        $this->provider($availableAdmin, 1, $date);
        $this->provider($otherAdmin, 2, $date, '10:30');
        DB::table('services')->insert([
            'slug' => 'psychotherapy',
            'name' => 'Psychotherapy',
            'enabled' => true,
            'duration_minutes' => 60,
        ]);

        $this->mock(SchedulingEngineService::class)
            ->shouldReceive('solve')
            ->once()
            ->andReturn(['job_status' => 'COMPLETED', 'decision' => 'FEASIBLE']);

        $response = $this->actingAs($client)->postJson('/api/booking/hold', [
            'service' => 'psychotherapy',
            'psychologist_id' => $availableAdmin->id,
            'date' => $date,
            'start_time' => '10:00',
        ]);

        $response->assertCreated();
        $this->assertSame(1, $response->json('hold.provider_id'));
    }

    public function test_scheduling_engine_receives_actual_service_duration(): void
    {
        $date = now()->addDays(3)->toDateString();
        $admin = $this->user('ADMIN');
        $this->provider($admin, 1, $date);
        Http::fake(['*' => Http::response([
            'job_status' => 'COMPLETED',
            'decision' => 'FEASIBLE',
        ], 200)]);

        app(SchedulingEngineService::class)->solve(
            serviceSlug: 'aba',
            date: $date,
            startTime: '10:00',
            endTime: '11:00',
        );

        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);
        $this->assertSame(60, $recorded->first()[0]->data()['candidate']['duration_minutes']);
    }


    private function user(string $role): User
    {
        $id = DB::table('users')->insertGetId([
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    private function provider(User $user, int $id, string $date, string $startTime = '09:00'): void
    {
        DB::table('providers')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('provider_availability')->insert([
            'provider_id' => $id,
            'start_at' => $date . ' ' . $startTime . ':00',
            'end_at' => $date . ' 17:00:00',
            'status' => 'available',
        ]);
    }
}
