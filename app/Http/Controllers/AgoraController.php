<?php

namespace App\Http\Controllers;

use App\Models\BookingPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use CyberDeep\LaravelAgoraTokenGenerator\Services\Agora;

class AgoraController extends Controller
{
    public function generateToken(Request $request)
    {
        $request->validate([
            'appointment_id' => ['required', 'integer', 'exists:booking_payments,id'],
        ]);

        $user = $request->user();
        $appointment = BookingPayment::findOrFail($request->integer('appointment_id'));

        $isAssignedProvider = in_array($user->role, ['ADMIN', 'AFFILIATE'], true)
            && (int) $appointment->psychologist_user_id === (int) $user->id;
        $isBookingClient = $user->role === 'CLIENT'
            && (int) $appointment->user_id === (int) $user->id;

        if (!$isAssignedProvider && !$isBookingClient) {
            return response()->json(['message' => 'You are not authorized to join this appointment.'], 403);
        }

        $serviceName = DB::table('services')
            ->where('slug', $appointment->service_id)
            ->value('name');
        $normalizedName = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $serviceName));
        if ($appointment->service_id !== 'psychotherapy' && !str_contains($normalizedName, 'psychotherapy')) {
            return response()->json(['message' => 'Video calls are only available for Psychotherapy appointments.'], 403);
        }

        $now = now('Asia/Manila')->toDateTimeString();
        $isWithinAppointmentWindow = DB::table('booking_payments')
            ->where('id', $appointment->id)
            ->where('start_at', '<=', $now)
            ->where('end_at', '>', $now)
            ->exists();
        $isApprovedAndInProgress = $appointment->status === 'verified'
            && $appointment->approval_status === 'approved'
            && !$appointment->cancelled_at
            && $isWithinAppointmentWindow;

        if (!$isApprovedAndInProgress) {
            return response()->json(['message' => 'Video calls are available only during the approved appointment time.'], 422);
        }

        $channelName = 'appointment-' . $appointment->id;
        $uid = $user->id;

        // Generate a token using the new package.
        $token = Agora::make($user->id)
            ->channel($channelName)
            ->uId($uid)
            ->join(false) // false = publisher (makakapag-video/audio)
            ->audioOnly(false) // false = includes video
            ->token();

        return response()->json([
            'token' => $token,
            'uid' => $uid,
            'appId' => env('AGORA_APP_ID'),
            'channelName' => $channelName,
        ]);
    }
}
