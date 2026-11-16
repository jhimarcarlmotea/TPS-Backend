<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminScheduleController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BulkScheduleAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('slot_holds');
        Schema::dropIfExists('booking_payments');
        Schema::dropIfExists('provider_availability');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('providers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->unique();
            $table->string('code')->unique();
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
            $table->string('created_by_role')->nullable();
            $table->timestamps();
        });
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('provider_id');
            $table->string('status');
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('approval_status');
        });
        Schema::create('slot_holds', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('provider_id');
            $table->unsignedInteger('user_id');
            $table->string('service_id');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->dateTime('expires_at');
            $table->timestamps();
        });
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('actor_name');
            $table->string('actor_role');
            $table->string('action');
            $table->string('category');
            $table->string('title');
            $table->text('description');
            $table->string('reference')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('slot_holds');
        Schema::dropIfExists('booking_payments');
        Schema::dropIfExists('provider_availability');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_it_adds_availability_to_all_selected_dates(): void
    {
        $dates = [
            now()->addDay()->toDateString(),
            now()->addDays(2)->toDateString(),
        ];

        $response = $this->store([
            'dates' => $dates,
            'status' => 'available',
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(2, $response->getData(true)['slots']);
        $this->assertSame(2, DB::table('provider_availability')->count());
    }

    public function test_it_keeps_single_date_availability_supported(): void
    {
        $date = now()->addDay()->toDateString();

        $response = $this->store([
            'date' => $date,
            'status' => 'available',
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($date, $response->getData(true)['date']);
        $this->assertSame(1, DB::table('provider_availability')->count());
    }

    public function test_overlapping_bulk_availability_is_rejected_without_partial_changes(): void
    {
        $dates = [
            now()->addDay()->toDateString(),
            now()->addDays(2)->toDateString(),
        ];
        DB::table('providers')->insert([
            'id' => 1,
            'user_id' => 42,
            'code' => 'P042',
            'display_name' => 'Schedule User',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('provider_availability')->insert([
            'provider_id' => 1,
            'start_at' => $dates[1] . ' 08:00:00',
            'end_at' => $dates[1] . ' 17:00:00',
            'status' => 'available',
            'created_by_role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->store([
                'dates' => $dates,
                'status' => 'available',
                'start_time' => '08:00',
                'end_time' => '17:00',
            ]);
            $this->fail('Expected overlapping availability to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dates', $exception->errors());
        }

        $this->assertSame(1, DB::table('provider_availability')->count());
    }

    public function test_it_blocks_a_time_without_removing_adjacent_online_availability(): void
    {
        $date = now()->addDay()->toDateString();
        $this->store([
            'date' => $date,
            'status' => 'available',
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);

        $response = $this->store([
            'date' => $date,
            'status' => 'blocked',
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $slots = DB::table('provider_availability')->orderBy('start_at')->get();
        $this->assertSame([
            ['08:00:00', '10:00:00', 'available'],
            ['10:00:00', '11:00:00', 'unavailable'],
            ['11:00:00', '17:00:00', 'available'],
        ], $slots->map(fn ($slot) => [
            substr($slot->start_at, 11),
            substr($slot->end_at, 11),
            $slot->status,
        ])->all());
        $this->assertSame(2, DB::table('provider_availability')->where('status', 'available')->count());
    }

    public function test_restoring_a_blocked_time_merges_adjacent_availability(): void
    {
        $date = now()->addDay()->toDateString();
        $this->store([
            'date' => $date,
            'status' => 'available',
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);
        $this->store([
            'date' => $date,
            'status' => 'blocked',
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);
        $blockedId = DB::table('provider_availability')->where('status', 'unavailable')->value('id');

        $response = $this->restore((int) $blockedId);

        $this->assertSame(200, $response->getStatusCode());
        $slot = DB::table('provider_availability')->first();
        $this->assertSame('available', $slot->status);
        $this->assertSame($date . ' 08:00:00', $slot->start_at);
        $this->assertSame($date . ' 17:00:00', $slot->end_at);
        $this->assertSame(1, DB::table('provider_availability')->count());
    }

    public function test_it_rejects_blocking_a_time_with_an_active_booking(): void
    {
        $date = now()->addDay()->toDateString();
        $this->store([
            'date' => $date,
            'status' => 'available',
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);
        DB::table('booking_payments')->insert([
            'provider_id' => 1,
            'status' => 'verified',
            'cancelled_at' => null,
            'start_at' => $date . ' 10:00:00',
            'end_at' => $date . ' 11:00:00',
            'approval_status' => 'approved',
        ]);

        try {
            $this->store([
                'date' => $date,
                'status' => 'blocked',
                'start_time' => '10:00',
                'end_time' => '11:00',
            ]);
            $this->fail('Expected blocking an active booking to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dates', $exception->errors());
        }

        $this->assertSame(1, DB::table('provider_availability')->count());
        $this->assertSame('available', DB::table('provider_availability')->value('status'));
    }

    public function test_it_rejects_blocking_a_time_with_an_active_online_hold(): void
    {
        $date = now()->addDay()->toDateString();
        $this->store([
            'date' => $date,
            'status' => 'available',
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);
        DB::table('slot_holds')->insert([
            'provider_id' => 1,
            'user_id' => 7,
            'service_id' => 'psychotherapy',
            'start_at' => $date . ' 12:00:00',
            'end_at' => $date . ' 13:00:00',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->store([
                'date' => $date,
                'status' => 'blocked',
                'start_time' => '12:00',
                'end_time' => '13:00',
            ]);
            $this->fail('Expected blocking an active online hold to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dates', $exception->errors());
        }

        $this->assertSame(1, DB::table('provider_availability')->count());
        $this->assertSame('available', DB::table('provider_availability')->value('status'));
    }

    private function store(array $payload)
    {
        $user = new User();
        $user->forceFill([
            'id' => 42,
            'first_name' => 'Schedule',
            'last_name' => 'User',
            'role' => 'ADMIN',
        ]);

        $request = Request::create('/api/admin/schedule/slots', 'POST', $payload);
        $request->setUserResolver(fn () => $user);

        return (new AdminScheduleController())->store($request);
    }

    private function restore(int $slotId)
    {
        $user = new User();
        $user->forceFill([
            'id' => 42,
            'first_name' => 'Schedule',
            'last_name' => 'User',
            'role' => 'ADMIN',
        ]);

        $request = Request::create("/api/admin/schedule/slots/{$slotId}/restore", 'POST');
        $request->setUserResolver(fn () => $user);

        return (new AdminScheduleController())->restoreBlockedTime($request, $slotId);
    }
}
