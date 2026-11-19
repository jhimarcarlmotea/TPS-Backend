<?php

namespace Tests\Feature;

use App\Mail\AppointmentReminder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppointmentReminderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('booking_payments');
        Schema::dropIfExists('client_profiles');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('services');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->default('CLIENT');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('providers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
        });
        Schema::create('client_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_payment_id')->nullable();
            $table->string('full_name');
            $table->string('email');
        });
        Schema::create('services', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug')->unique();
            $table->string('name');
        });
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('provider_id')->nullable();
            $table->string('service_id');
            $table->string('status');
            $table->string('approval_status');
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->dateTime('one_hour_reminder_sent_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('booking_payments');
        Schema::dropIfExists('client_profiles');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('services');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_it_sends_one_reminder_for_each_approved_booking_within_24_hours(): void
    {
        Mail::fake();
        $this->travelTo(now('Asia/Manila')->startOfMinute());
        $clientId = $this->user('Mika', 'Santos', 'mika@example.test');
        $psychologistId = $this->user('Taylor', 'Reyes', 'psychologist@example.test');
        DB::table('providers')->insert(['id' => 1, 'user_id' => $psychologistId]);
        DB::table('services')->insert(['slug' => 'aba', 'name' => 'ABA Therapy']);

        $appointmentStart = now('Asia/Manila')->addHours(24)->format('Y-m-d H:i:s');
        $bookingId = $this->booking($clientId, 1, 'TF-REMINDER-1', $appointmentStart);
        DB::table('client_profiles')->insert([
            'booking_payment_id' => $bookingId,
            'full_name' => 'Mika Santos',
            'email' => 'mika-booking@example.test',
        ]);
        $this->booking($clientId, 1, 'TF-REMINDER-2', now('Asia/Manila')->addHours(25)->format('Y-m-d H:i:s'));
        $this->booking(
            $clientId,
            1,
            'TF-REMINDER-3',
            now('Asia/Manila')->addHours(12)->format('Y-m-d H:i:s'),
            approvalStatus: 'pending'
        );

        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Mail::assertSent(AppointmentReminder::class, 1);
        Mail::assertSent(AppointmentReminder::class, function (AppointmentReminder $mail) {
            return $mail->firstName === 'Mika'
                && $mail->serviceName === 'ABA Therapy'
                && $mail->psychologistName === 'Taylor Reyes'
                && $mail->reference === 'TF-REMINDER-1'
                && !$mail->isOneHourReminder
                && $mail->hasTo('mika-booking@example.test');
        });
        $this->assertNotNull(
            DB::table('booking_payments')->where('reference', 'TF-REMINDER-1')->value('reminder_sent_at')
        );
        $this->assertNull(
            DB::table('booking_payments')->where('reference', 'TF-REMINDER-2')->value('reminder_sent_at')
        );
    }

    public function test_it_sends_a_separate_one_hour_reminder_once(): void
    {
        Mail::fake();
        $this->travelTo(now('Asia/Manila')->startOfMinute());
        $clientId = $this->user('Mika', 'Santos', 'mika@example.test');
        DB::table('services')->insert(['slug' => 'aba', 'name' => 'ABA Therapy']);
        $bookingId = $this->booking(
            $clientId,
            null,
            'TF-REMINDER-1H',
            now('Asia/Manila')->addMinutes(55)->format('Y-m-d H:i:s')
        );

        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Mail::assertSent(AppointmentReminder::class, 1);
        Mail::assertSent(AppointmentReminder::class, function (AppointmentReminder $mail) {
            return $mail->isOneHourReminder
                && $mail->hasTo('mika@example.test');
        });
        $this->assertNotNull(
            DB::table('booking_payments')->where('id', $bookingId)->value('one_hour_reminder_sent_at')
        );
        $this->assertNull(
            DB::table('booking_payments')->where('id', $bookingId)->value('reminder_sent_at')
        );
    }

    public function test_reminder_email_renders_the_booking_details_in_the_branded_template(): void
    {
        $html = (new AppointmentReminder(
            firstName: 'Mika',
            serviceName: 'ABA Therapy',
            appointmentDate: 'Thursday, November 19, 2026',
            startTime: '8:00 AM',
            endTime: '9:00 AM',
            psychologistName: 'Taylor Reyes',
            reference: 'TF-REMINDER-1',
        ))->render();

        $this->assertStringContainsString('THREEFOLD', $html);
        $this->assertStringContainsString('Thursday, November 19, 2026', $html);
        $this->assertStringContainsString('ABA Therapy', $html);
        $this->assertStringContainsString('Taylor Reyes', $html);
        $this->assertStringContainsString('TF-REMINDER-1', $html);

        $oneHourHtml = (new AppointmentReminder(
            firstName: 'Mika',
            serviceName: 'ABA Therapy',
            appointmentDate: 'Thursday, November 19, 2026',
            startTime: '8:00 AM',
            endTime: '9:00 AM',
            psychologistName: 'Taylor Reyes',
            reference: 'TF-REMINDER-1',
            isOneHourReminder: true,
        ))->render();

        $this->assertStringContainsString('coming up in about one hour', $oneHourHtml);
    }

    private function user(string $firstName, string $lastName, string $email): int
    {
        return DB::table('users')->insertGetId([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function booking(
        int $userId,
        ?int $providerId,
        string $reference,
        string $startAt,
        string $approvalStatus = 'approved'
    ): int {
        return DB::table('booking_payments')->insertGetId([
            'user_id' => $userId,
            'provider_id' => $providerId,
            'reference' => $reference,
            'service_id' => 'aba',
            'status' => 'verified',
            'approval_status' => $approvalStatus,
            'start_at' => $startAt,
            'end_at' => date('Y-m-d H:i:s', strtotime($startAt . ' +1 hour')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
