<?php

namespace Tests\Feature;

use App\Http\Controllers\BookingPaymentController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingPaymentOptionsCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::store()->flush();

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->boolean('enabled');
        });
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('downpayment_percent')->nullable();
            $table->decimal('psychotherapy_downpayment_amount', 10, 2)->nullable();
            $table->decimal('assessment_downpayment_amount', 10, 2)->nullable();
            $table->string('note')->nullable();
        });
        Schema::create('service_prices', function (Blueprint $table) {
            $table->id();
            $table->string('service_id');
            $table->decimal('price', 10, 2);
        });
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('letter')->nullable();
            $table->string('badge')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('bank')->nullable();
            $table->string('qr_path')->nullable();
            $table->boolean('enabled');
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('service_prices');
        Schema::dropIfExists('payment_settings');
        Schema::dropIfExists('services');

        parent::tearDown();
    }

    public function test_payment_options_cache_returns_the_expected_payload_and_keeps_unavailable_services_as_404(): void
    {
        DB::table('services')->insert([
            ['slug' => 'psychotherapy', 'name' => 'Psychotherapy', 'enabled' => true],
            ['slug' => 'aba', 'name' => 'ABA Therapy', 'enabled' => false],
        ]);
        DB::table('payment_settings')->insert([
            'downpayment_percent' => 50,
            'psychotherapy_downpayment_amount' => 250,
            'assessment_downpayment_amount' => 300,
            'note' => 'Payment note',
        ]);
        DB::table('service_prices')->insert([
            'service_id' => 'psychotherapy',
            'price' => 1000,
        ]);

        $controller = new BookingPaymentController();
        $request = Request::create('/api/booking/payment-options', 'GET', [
            'service' => 'psychotherapy',
        ]);

        $first = $controller->options($request);
        $second = $controller->options($request);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertEquals(1000, $first->getData(true)['price']);
        $this->assertTrue($first->getData(true)['downpayment_allowed']);

        $unavailable = $controller->options(Request::create('/api/booking/payment-options', 'GET', [
            'service' => 'aba',
        ]));

        $this->assertSame(404, $unavailable->getStatusCode());
    }
}
