<?php

namespace App\Console\Commands;

use App\Mail\AppointmentReminder;
use App\Models\BookingPayment;
use App\Models\ClientProfile;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';
    protected $description = 'Email clients 24-hour and 1-hour reminders for approved appointments.';

    public function handle(): int
    {
        $now = now('Asia/Manila');
        $oneHourBefore = $now->copy()->addHour();
        $dayBefore = $now->copy()->addDay();

        $bookingIds = BookingPayment::query()
            ->where('status', 'verified')
            ->where('approval_status', 'approved')
            ->whereNull('cancelled_at')
            ->where(function ($query) use ($now, $oneHourBefore, $dayBefore) {
                $query->where(function ($dayReminder) use ($oneHourBefore, $dayBefore) {
                    $dayReminder->whereNull('reminder_sent_at')
                        ->where('start_at', '>', $oneHourBefore)
                        ->where('start_at', '<=', $dayBefore);
                })->orWhere(function ($hourReminder) use ($now, $oneHourBefore) {
                    $hourReminder->whereNull('one_hour_reminder_sent_at')
                        ->where('start_at', '>', $now)
                        ->where('start_at', '<=', $oneHourBefore);
                });
            })
            ->orderBy('start_at')
            ->pluck('id');

        $sent = 0;
        foreach ($bookingIds as $bookingId) {
            $sent += DB::transaction(function () use ($bookingId) {
                $booking = BookingPayment::query()
                    ->lockForUpdate()
                    ->find($bookingId);

                if (!$booking
                    || $booking->status !== 'verified'
                    || $booking->approval_status !== 'approved'
                    || $booking->cancelled_at !== null) {
                    return 0;
                }

                $now = now('Asia/Manila');
                $startAt = Carbon::parse($booking->getRawOriginal('start_at'), 'Asia/Manila')->locale('en');
                if ($startAt->lte($now)) {
                    return 0;
                }

                $isOneHourReminder = $startAt->lte($now->copy()->addHour());
                if ($isOneHourReminder) {
                    if ($booking->one_hour_reminder_sent_at !== null) {
                        return 0;
                    }
                } elseif ($booking->reminder_sent_at !== null || $startAt->gt($now->copy()->addDay())) {
                    return 0;
                }

                $client = User::find($booking->user_id);
                if (!$client) {
                    Log::warning('Appointment reminder skipped: client has no valid email.', [
                        'booking_id' => $booking->id,
                    ]);
                    return 0;
                }
                $clientProfile = ClientProfile::where('booking_payment_id', $booking->id)->first();
                $recipient = $clientProfile?->email ?: $client->email;
                if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    Log::warning('Appointment reminder skipped: client has no valid email.', [
                        'booking_id' => $booking->id,
                    ]);
                    return 0;
                }

                $provider = Provider::find($booking->provider_id);
                $psychologist = $provider ? User::find($provider->user_id) : null;
                $serviceName = DB::table('services')
                    ->where('slug', $booking->service_id)
                    ->value('name')
                    ?? ucfirst(str_replace('-', ' ', $booking->service_id));

                $profileName = trim((string) $clientProfile?->full_name);
                $firstName = $profileName !== ''
                    ? explode(' ', $profileName, 2)[0]
                    : ($client->first_name ?: 'there');

                Mail::to($recipient)->send(new AppointmentReminder(
                    firstName: $firstName,
                    serviceName: $serviceName,
                    appointmentDate: $startAt->isoFormat('dddd, MMMM D, YYYY'),
                    startTime: $startAt->format('g:i A'),
                    endTime: Carbon::parse($booking->getRawOriginal('end_at'), 'Asia/Manila')->format('g:i A'),
                    psychologistName: $psychologist
                        ? trim($psychologist->first_name . ' ' . $psychologist->last_name)
                        : null,
                    reference: $booking->reference,
                    isOneHourReminder: $isOneHourReminder,
                ));

                $sentAtColumn = $isOneHourReminder ? 'one_hour_reminder_sent_at' : 'reminder_sent_at';
                $booking->forceFill([$sentAtColumn => $now])->save();

                return 1;
            });
        }

        $this->info("Sent {$sent} appointment reminder(s).");

        return self::SUCCESS;
    }
}
