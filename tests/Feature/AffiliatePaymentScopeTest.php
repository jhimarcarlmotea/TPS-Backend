<?php

namespace Tests\Feature;

use App\Http\Controllers\BookingPaymentController;
use App\Http\Controllers\DashboardController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AffiliatePaymentScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('booking_payments');
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();
            $table->string('service_id');
            $table->unsignedBigInteger('psychologist_user_id')->nullable();
            $table->string('status')->default('verified');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('booking_payments');

        parent::tearDown();
    }

    public function test_affiliate_sees_payments_for_custom_psychotherapy_service_slugs(): void
    {
        DB::table('booking_payments')->insert([
            ['id' => 1, 'service_id' => 'psychotherapy-counseling', 'psychologist_user_id' => 42],
            ['id' => 2, 'service_id' => 'psychotherapy', 'psychologist_user_id' => 43],
            ['id' => 3, 'service_id' => 'aba-therapy', 'psychologist_user_id' => null],
        ]);

        $affiliate = new User();
        $affiliate->forceFill(['id' => 42, 'role' => 'AFFILIATE']);

        $method = new \ReflectionMethod(BookingPaymentController::class, 'scoped');
        $method->setAccessible(true);
        $payments = $method->invoke(new BookingPaymentController(), $affiliate);

        $this->assertSame([1], $payments->orderBy('id')->pluck('id')->all());

        $dashboardMethod = new \ReflectionMethod(DashboardController::class, 'scoped');
        $dashboardMethod->setAccessible(true);
        $dashboardPayments = $dashboardMethod->invoke(new DashboardController(), $affiliate);

        $this->assertSame([1], $dashboardPayments->orderBy('id')->pluck('id')->all());
    }
}
