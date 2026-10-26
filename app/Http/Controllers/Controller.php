<?php

namespace App\Http\Controllers;

abstract class Controller
{
    public function handleGoogleAuth(Request $request)
    {
        $credential = $request->input('credential');

        if (!$credential) {
            return response()->json(['message' => 'Google credential token is required'], 400);
        }

        // I-verify ang Google Token gamit ang API ng Google
        $googleResponse = \Illuminate\Support\Facades\Http::get("https://oauth2.googleapis.com/tokeninfo?id_token={$credential}");

        if ($googleResponse->failed()) {
            return response()->json(['message' => 'Invalid Google token'], 401);
        }

        $payload = $googleResponse->json();
        $googleId = $payload['sub'];
        $email = $payload['email'];
        $firstName = $payload['given_name'] ?? 'Google';
        $lastName = $payload['family_name'] ?? 'User';

        // Hanapin ang user gamit ang google_id o email
        $user = \App\Models\User::where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if (!$user) {
            $user = \App\Models\User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'google_id' => $googleId,
                'email_verified_at' => now(),
                'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(16)),
                'role' => 'CLIENT',
            ]);
        } else {
            if (!$user->google_id) {
                $user->update([
                    'google_id' => $googleId,
                    'email_verified_at' => $user->email_verified_at ?? now()
                ]);
            }
        }

        // Gumawa ng token gamit ang Laravel Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }
}
