<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login a system user (email/password).
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account has been disabled.'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Register a new system user.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|string|min:8',
        ]);

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'role'       => 'CLIENT',
            'login_provider' => 'SYSTEM',
            'is_active'  => 1,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ], 201);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    /**
     * Logout (revoke current token).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Handle Google Auth (via @react-oauth/google credential).
     */
    public function handleGoogleAuth(Request $request)
    {
        $credential = $request->input('credential');

        if (!$credential) {
            return response()->json(['message' => 'Google credential token is required'], 400);
        }

        // I-verify ang Google Token gamit ang API ng Google
        $googleResponse = Http::get("https://oauth2.googleapis.com/tokeninfo?id_token={$credential}");

        if ($googleResponse->failed()) {
            return response()->json(['message' => 'Invalid Google token'], 401);
        }

        $payload = $googleResponse->json();
        $googleId = $payload['sub'];
        $email = $payload['email'];
        $firstName = $payload['given_name'] ?? 'Google';
        $lastName = $payload['family_name'] ?? 'User';

        // Hanapin ang user gamit ang google_id o email
        $user = User::where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if (!$user) {
            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'google_id' => $googleId,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(16)), // Random password for OAuth users
                'role' => 'CLIENT',
                'login_provider' => 'GOOGLE',
                'is_active' => 1,
            ]);
        } else {
            if (!$user->google_id) {
                $user->update([
                    'google_id' => $googleId,
                    'email_verified_at' => $user->email_verified_at ?? now()
                ]);
            }
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }
}