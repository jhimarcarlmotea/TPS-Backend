<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class SocialAuthController extends Controller
{
    // ---------- GOOGLE ----------
    public function google(Request $request)
    {
        $request->validate(['credential' => 'required|string']);

        $res = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $request->credential,
        ]);
        if ($res->failed()) {
            return $this->error('Google sign-in failed. Please try again.', 401);
        }
        $g = $res->json();

        // The token must be made for OUR app
        if (($g['aud'] ?? null) !== config('services.google.client_id')) {
            return $this->error('Invalid Google sign-in.', 401);
        }

        // Google must have verified this email
        if (!filter_var($g['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $this->error('Your Google email is not verified.', 403);
        }

        $user = User::where('google_id', $g['sub'])
            ->orWhere('email', $g['email'])
            ->first();

        if (!$user) {
            $user = new User();
            $user->forceFill([
                'role'              => 'CLIENT',
                'first_name'        => $g['given_name'] ?? 'Google',
                'last_name'         => $g['family_name'] ?? '-',
                'email'             => $g['email'],
                'password'          => Hash::make(Str::random(40)),
                'google_id'         => $g['sub'],
                'email_verified_at' => now(),
                'is_active'         => true,
            ])->save();
        } else {
            if (!$user->google_id) {
                $user->google_id = $g['sub'];
            }
            if (!$user->email_verified_at) {
                $user->email_verified_at = now();
            }
            $user->save();
        }

        if (!$user->is_active) {
            return $this->error('This account is disabled.', 403);
        }
        
        
        if (!$user->avatar_path && !empty($g['picture'])) {
            $this->saveRemoteAvatar($user, str_replace('=s96-c', '=s400-c', $g['picture']));
        }
                $pic = $me['picture']['data'] ?? [];
        if (!$user->avatar_path && !empty($pic['url']) && empty($pic['is_silhouette'])) {
            $this->saveRemoteAvatar($user, $pic['url']);
        }
        return $this->authResponse($user);
    }

    // ---------- FACEBOOK ----------
        // ---------- FACEBOOK ----------
    public function facebook(Request $request)
    {
        $request->validate(['access_token' => 'required|string']);

        $appId  = config('services.facebook.app_id');
        $secret = config('services.facebook.app_secret');
        $token  = $request->access_token;

        // Make sure the token belongs to OUR Facebook app
        $debug = Http::get('https://graph.facebook.com/debug_token', [
            'input_token'  => $token,
            'access_token' => $appId . '|' . $secret,
        ])->json('data');

        if (!($debug['is_valid'] ?? false) || (string) ($debug['app_id'] ?? '') !== (string) $appId) {
            return $this->error('Facebook sign-in failed. Please try again.', 401);
        }

        $me = Http::get('https://graph.facebook.com/me', [
            'fields'       => 'id,first_name,last_name,email',
            'access_token' => $token,
        ])->json();

        if (empty($me['id'])) {
            return $this->error('Facebook sign-in failed. Please try again.', 401);
        }

        // Some Facebook accounts have no email (phone-only). Use a placeholder.
        $email = 'fb_' . $me['id'] . '@facebook.local';
        $user = User::where('facebook_id', $me['id'])->first();

        if (!$user) {
            // Don't take over an account that already uses this email
            

            $user = new User();
            $user->forceFill([
                'role'              => 'CLIENT',
                'first_name'        => $me['first_name'] ?? 'Facebook',
                'last_name'         => $me['last_name'] ?? '-',
                'email'             => $email,
                'google_id'         => $g['sub'],
                'login_provider'    => 'GOOGLE',
                'facebook_id'       => $me['id'],
                'login_provider'    => 'FACEBOOK',
                'password'          => Hash::make(Str::random(40)),
                'facebook_id'       => $me['id'],
                'email_verified_at' => now(),
                'is_active'         => true,
            ])->save();
        }

        if (!$user->is_active) {
            return $this->error('This account is disabled.', 403);
        }
        
        if (!$user->avatar_path && !empty($g['picture'])) {
            $this->saveRemoteAvatar($user, str_replace('=s96-c', '=s400-c', $g['picture']));
        }
        $pic = $me['picture']['data'] ?? [];
        if (!$user->avatar_path && !empty($pic['url']) && empty($pic['is_silhouette'])) {
            $this->saveRemoteAvatar($user, $pic['url']);
        }
        return $this->authResponse($user);
    }
    // Downloads the Google/Facebook picture and keeps our own copy
    // (their links can expire, so we don't store the link itself).
    private function saveRemoteAvatar(User $user, string $url): void
    {
        try {
            $res = Http::timeout(8)->get($url);
            if (!$res->successful() || @getimagesizefromstring($res->body()) === false) {
                return;
            }

            $path = 'avatars/' . $user->id . '-' . time() . '.jpg';
            Storage::disk('public')->put($path, $res->body());
            $user->forceFill(['avatar_path' => $path])->save();
        } catch (\Throwable $e) {
            // No picture is not a reason to block the login.
        }
    }
    // ---------- VERIFY CODE ----------
    public function verify(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'code'  => 'required|digits:6',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (
            !$user ||
            !$user->verification_code ||
            !$user->verification_expires_at ||
            now()->greaterThan($user->verification_expires_at) ||
            !Hash::check($data['code'], $user->verification_code)
        ) {
            return $this->error('Invalid or expired code.', 422);
        }

        $user->forceFill([
            'email_verified_at'       => now(),
            'verification_code'       => null,
            'verification_expires_at' => null,
        ])->save();

        return $this->authResponse($user);
    }

    // ---------- RESEND CODE ----------
    public function resend(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);

        $user = User::where('email', $data['email'])->first();
        if ($user && !$user->email_verified_at) {
            $this->sendCode($user);
        }

        // Same answer either way, so nobody can check which emails exist
        return response()->json(['message' => 'If the account exists, a new code was sent.']);
    }

    // ---------- HELPERS ----------
    private function sendCode(User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'verification_code'       => Hash::make($code),
            'verification_expires_at' => now()->addMinutes(10),
        ])->save();

        Mail::raw(
            "Your Threefold verification code is: {$code}\n\nIt expires in 10 minutes. If you did not request this, you can ignore this email.",
            function ($m) use ($user) {
                $m->to($user->email)->subject('Your Threefold verification code');
            }
        );
    }

    private function authResponse(User $user)
    {
        // IMPORTANT: make this line the same as in your existing /login code
        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $user->makeHidden(['verification_code', 'verification_expires_at']),
        ]);
    }

    private function error(string $message, int $status)
    {
        return response()->json(['message' => $message], $status);
    }
}