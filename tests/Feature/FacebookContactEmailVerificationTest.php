<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FacebookContactEmailVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facebook.app_id' => 'facebook-app',
            'services.facebook.app_secret' => 'facebook-secret',
        ]);

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('role');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('background')->nullable();
            $table->string('password');
            $table->string('login_provider')->default('SYSTEM');
            $table->string('google_id')->nullable()->unique();
            $table->string('facebook_id')->nullable()->unique();
            $table->string('avatar_path')->nullable();
            $table->string('verification_code')->nullable();
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('pending_email')->nullable();
            $table->string('pending_email_code')->nullable();
            $table->timestamp('pending_email_expires_at')->nullable();
            $table->unsignedTinyInteger('pending_email_attempts')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_new_facebook_user_must_confirm_and_verify_a_reminder_email(): void
    {
        $this->fakeFacebookProfile(null);

        $this->postJson('/api/auth/facebook', ['access_token' => 'valid-facebook-token'])
            ->assertOk()
            ->assertJson([
                'needs_email' => true,
                'email' => '',
            ]);

        Mail::shouldReceive('raw')
            ->once()
            ->withArgs(fn (string $message, callable $callback) => str_contains($message, 'verification code'));

        $this->postJson('/api/auth/facebook/email', [
            'access_token' => 'valid-facebook-token',
            'email' => 'reminders@example.test',
        ])
            ->assertForbidden()
            ->assertJson([
                'needs_verification' => true,
                'email' => 'reminders@example.test',
            ]);

        $user = User::where('facebook_id', 'facebook-user-1')->firstOrFail();
        $this->assertSame('Alex', $user->first_name);
        $this->assertSame('Morgan Lee', $user->last_name);
        $this->assertSame('FACEBOOK', $user->login_provider);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->verification_code);
        $this->assertTrue($user->is_active);
    }

    public function test_facebook_email_cannot_take_over_an_existing_account(): void
    {
        $this->fakeFacebookProfile(null);
        User::create([
            'role' => 'CLIENT',
            'first_name' => 'Existing',
            'last_name' => 'Account',
            'email' => 'existing@example.test',
            'password' => Hash::make('password'),
            'login_provider' => 'SYSTEM',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/facebook/email', [
            'access_token' => 'valid-facebook-token',
            'email' => 'existing@example.test',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'That email is already linked to another account. Sign in to that account to manage its login methods.');

        $this->assertDatabaseHas('users', [
            'email' => 'existing@example.test',
            'login_provider' => 'SYSTEM',
            'facebook_id' => null,
        ]);
        $this->assertDatabaseCount('users', 1);
    }

    private function fakeFacebookProfile(?string $email): void
    {
        Http::fake([
            'graph.facebook.com/debug_token*' => Http::response([
                'data' => ['is_valid' => true, 'app_id' => 'facebook-app'],
            ]),
            'graph.facebook.com/me*' => Http::response([
                'id' => 'facebook-user-1',
                'first_name' => 'Alex',
                'last_name' => 'Morgan Lee',
                'name' => 'Alex Morgan Lee',
                'email' => $email,
            ]),
        ]);
    }
}
