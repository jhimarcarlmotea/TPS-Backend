<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    public function google(Request $request)
    {
        $request->validate(['credential' => 'required|string']);

        $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $request->credential,
        ]);

        if ($response->failed()) {
            return $this->error('Google sign-in failed. Please try again.', 401);
        }

        $payload = $response->json();

        if (($payload['aud'] ?? null) !== config('services.google.client_id')) {
            return $this->error('Invalid Google sign-in.', 401);
        }

        if (! filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $this->error('Your Google email is not verified.', 403);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $googleId = (string) ($payload['sub'] ?? '');

        if ($email === '' || $googleId === '') {
            return $this->error('Google sign-in failed. Please try again.', 401);
        }

        $user = User::where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if (! $user) {
            $user = User::create([
                'first_name' => $payload['given_name'] ?? 'Google',
                'last_name' => $payload['family_name'] ?? 'User',
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'role' => 'CLIENT',
                'login_provider' => 'GOOGLE',
                'google_id' => $googleId,
                'is_active' => true,
            ]);
        }

        $user->login_provider = 'GOOGLE';
        $user->google_id = $googleId;
        $user->email = $email;

        if (! $user->is_active) {
            return $this->error('This account is disabled.', 403);
        }

        if (! $user->email_verified_at || ! empty($user->verification_code)) {
            $this->sendCode($user);

            return response()->json([
                'needs_verification' => true,
                'email' => $user->email,
                'message' => 'A 6-digit verification code was sent to your email.',
            ], 403);
        }

        if (! $user->avatar_path && ! empty($payload['picture'])) {
            $this->saveRemoteAvatar($user, str_replace('=s96-c', '=s400-c', $payload['picture']));
        }

        $user->save();

        return $this->authResponse($user);
    }

    public function facebook(Request $request)
    {
        $request->validate(['access_token' => 'required|string']);

        $profile = $this->facebookProfile($request->access_token);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $user = User::where('facebook_id', $profile['id'])->first();

        if ($user && ! $user->is_active) {
            return $this->error('This account is disabled.', 403);
        }

        if ($user && ! str_ends_with($user->email, '@facebook.local')) {
            if (! $user->email_verified_at) {
                $this->sendCode($user);

                return $this->verificationRequired($user->email);
            }

            $user->forceFill(['login_provider' => 'FACEBOOK'])->save();
            return $this->authResponse($user);
        }

        return response()->json([
            'needs_email' => true,
            'email' => $profile['email'] ?? '',
            'message' => 'Confirm the email address where you want to receive appointment reminders.',
        ]);
    }

    public function facebookEmail(Request $request)
    {
        $data = $request->validate([
            'access_token' => 'required|string',
            'email' => 'required|email|max:190',
        ]);

        $profile = $this->facebookProfile($data['access_token']);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        $email = strtolower(trim($data['email']));
        $user = User::where('facebook_id', $profile['id'])->first();
        $emailOwner = User::where('email', $email)
            ->when($user, fn ($query) => $query->where('id', '!=', $user->id))
            ->first();

        if ($emailOwner) {
            return $this->error(
                'That email is already linked to another account. Sign in to that account to manage its login methods.',
                409
            );
        }

        if ($user && $user->email_verified_at && $user->email !== $email) {
            return $this->error('This Facebook account already has a verified reminder email. Sign in and change it from your profile.', 422);
        }

        if (! $user) {
            $name = trim((string) ($profile['name'] ?? ''));
            $nameParts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $firstName = $profile['first_name'] ?? ($nameParts[0] ?? 'Facebook');
            $lastName = $profile['last_name'] ?? (count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'User');

            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'role' => 'CLIENT',
                'login_provider' => 'FACEBOOK',
                'facebook_id' => $profile['id'],
                'is_active' => true,
            ]);
        } else {
            $user->forceFill([
                'email' => $email,
                'login_provider' => 'FACEBOOK',
            ])->save();
        }

        if (! $user->is_active) {
            return $this->error('This account is disabled.', 403);
        }

        $picture = $profile['picture']['data']['url'] ?? null;
        if (! $user->avatar_path && $picture) {
            $this->saveRemoteAvatar($user, $picture);
        }

        $this->sendCode($user);

        return $this->verificationRequired($user->email);
    }

    private function facebookProfile(string $token): array|JsonResponse
    {
        $appId = config('services.facebook.app_id');
        $secret = config('services.facebook.app_secret');
        if (! $appId || ! $secret) {
            return $this->error('Facebook sign-in is not configured on the server.', 503);
        }

        $debug = Http::timeout(10)->get('https://graph.facebook.com/debug_token', [
            'input_token' => $token,
            'access_token' => $appId . '|' . $secret,
        ]);

        if ($debug->failed()) {
            return $this->error('Facebook sign-in failed. Please try again.', 401);
        }

        $debugData = $debug->json('data');
        if (! ($debugData['is_valid'] ?? false) || (string) ($debugData['app_id'] ?? '') !== (string) $appId) {
            return $this->error('Facebook sign-in failed. Please try again.', 401);
        }

        $response = Http::timeout(10)->get('https://graph.facebook.com/me', [
            'fields' => 'id,first_name,last_name,name,email,picture',
            'access_token' => $token,
        ]);

        if ($response->failed() || empty($response->json('id'))) {
            return $this->error('Facebook sign-in failed. Please try again.', 401);
        }

        return $response->json();
    }

    private function verificationRequired(string $email): JsonResponse
    {
        return response()->json([
            'needs_verification' => true,
            'email' => $email,
            'message' => 'A 6-digit verification code was sent to your email.',
        ], 403);
    }

    public function verify(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
        ]);

        $user = User::where('email', strtolower(trim($data['email'])))->first();

        if (
            ! $user ||
            ! $user->verification_code ||
            ! $user->verification_expires_at ||
            now()->greaterThan($user->verification_expires_at) ||
            ! Hash::check((string) $data['code'], $user->verification_code)
        ) {
            return $this->error('Invalid or expired code.', 422);
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'verification_code' => null,
            'verification_expires_at' => null,
        ])->save();

        return $this->authResponse($user);
    }

    public function resend(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);

        $user = User::where('email', strtolower(trim($data['email'])))->first();

        if ($user && ! $user->email_verified_at) {
            $this->sendCode($user);
        }

        return response()->json(['message' => 'If the account exists, a new code was sent.']);
    }

    private function sendCode(User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'verification_code' => Hash::make($code),
            'verification_expires_at' => now()->addMinutes(10),
        ])->save();

        Mail::raw(
            "Your Threefold verification code is: {$code}\n\nIt expires in 10 minutes. If you did not request this, you can ignore this email.",
            function ($message) use ($user) {
                $message->to($user->email)->subject('Your Threefold verification code');
            }
        );
    }

    private function saveRemoteAvatar(User $user, string $url): void
    {
        try {
            $response = Http::timeout(8)->get($url);
            if (! $response->successful() || @getimagesizefromstring($response->body()) === false) {
                return;
            }

            $path = 'avatars/' . $user->id . '-' . time() . '.jpg';
            Storage::disk('public')->put($path, $response->body());
            $user->forceFill(['avatar_path' => $path])->save();
        } catch (\Throwable $e) {
            // Ignore if no avatar can be fetched.
        }
    }

    private function authResponse(User $user)
    {
        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->makeHidden(['verification_code', 'verification_expires_at']),
        ]);
    }

    private function error(string $message, int $status)
    {
        return response()->json(['message' => $message], $status);
    }
}
