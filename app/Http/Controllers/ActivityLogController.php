<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BookingPayment;
use App\Models\ClientProfile;
use App\Models\Provider;
use App\Models\ProviderAvailability;
use App\Models\User;
use App\Support\TpsCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityLogController extends Controller
{
    private const SERVICE_NAMES = [
        'psychotherapy' => 'Psychotherapy',
        'aba'           => 'ABA Therapy',
        'assessment'    => 'Assessment',
    ];

    /**
     * GET /api/admin/activity-logs
     */
    public function index(Request $request)
    {
        // Auto-backfill if activity logs table is empty
        if (ActivityLog::count() === 0) {
            $this->backfillHistoricalLogs();
        }

        $user = $request->user();
        $identity = serialize([
            'viewer' => [$user->role, $user->id],
            'q' => trim((string) $request->input('q', '')),
            'role' => $request->input('role'),
            'category' => $request->input('category'),
            'time_range' => $request->input('time_range'),
            'page' => max(1, (int) $request->input('page', 1)),
            'per_page' => max(10, min(100, (int) $request->input('per_page', 25))),
            'date' => now()->toDateString(),
        ]);

        return response()->json(TpsCache::remember('reports', 'activity-logs:' . $identity, 15, function () use ($request) {
        $query = ActivityLog::query();

        // Search
        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($w) use ($q) {
                $w->where('actor_name', 'like', "%{$q}%")
                  ->orWhere('title', 'like', "%{$q}%")
                  ->orWhere('description', 'like', "%{$q}%")
                  ->orWhere('reference', 'like', "%{$q}%");
            });
        }

        // Filter by Role: ALL | ADMIN | AFFILIATE | CLIENT | SYSTEM
        if ($role = $request->input('role')) {
            $upper = strtoupper(trim($role));
            if ($upper !== 'ALL') {
                $query->where('actor_role', $upper);
            }
        }

        // Filter by Category: appointment | payment | schedule | account | service | backup | system
        if ($cat = $request->input('category')) {
            $catLower = strtolower(trim($cat));
            if ($catLower !== 'all') {
                $query->where('category', $catLower);
            }
        }

        // Filter by Time Range
        if ($range = $request->input('time_range')) {
            $now = Carbon::now();
            match ($range) {
                'today'      => $query->whereDate('created_at', $now->toDateString()),
                'this_week'  => $query->whereBetween('created_at', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]),
                'this_month' => $query->whereMonth('created_at', $now->month)->whereYear('created_at', $now->year),
                default      => null,
            };
        }

        // Global stats (unfiltered by search for high-level counter visibility)
        $totalActivities = ActivityLog::count();
        $adminActions    = ActivityLog::where('actor_role', 'ADMIN')->count();
        $affiliateActions= ActivityLog::where('actor_role', 'AFFILIATE')->count();
        $clientActions   = ActivityLog::where('actor_role', 'CLIENT')->count();
        $paymentEvents   = ActivityLog::where('category', 'payment')->count();
        $rescheduleCancel= ActivityLog::whereIn('action', ['appointment_rescheduled', 'appointment_cancelled'])->count();

        $perPage = max(10, min(100, (int) $request->input('per_page', 25)));
        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return [
            'logs' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'total'        => $logs->total(),
                'per_page'     => $logs->perPage(),
            ],
            'stats' => [
                'total_activities'   => $totalActivities,
                'admin_actions'      => $adminActions,
                'affiliate_actions'  => $affiliateActions,
                'client_actions'     => $clientActions,
                'payment_events'     => $paymentEvents,
                'reschedule_cancel'  => $rescheduleCancel,
            ],
        ];
        }));
    }

    /**
     * Backfill existing database appointments and actions into activity_logs
     */
    public function backfillHistoricalLogs(): void
    {
        $users = User::all()->keyBy('id');
        $providers = Provider::all()->keyBy('id');
        $serviceNames = DB::table('services')->pluck('name', 'slug')->all();

        // 1. Booking Payments
        $bookings = BookingPayment::all();
        $clientProfiles = ClientProfile::all()->groupBy('booking_payment_id');

        foreach ($bookings as $b) {
            $clientUser = $users[$b->user_id] ?? null;
            $profile = $clientProfiles->get($b->id)?->first();
            $clientName = $profile?->full_name
                ?: trim(($clientUser?->first_name ?? '') . ' ' . ($clientUser?->last_name ?? ''))
                ?: 'Client';

            $sName = self::SERVICE_NAMES[$b->service_id] ?? ($serviceNames[$b->service_id] ?? ucfirst($b->service_id));
            if ($b->assessment_type_name) {
                $sName .= " ({$b->assessment_type_name})";
            }

            // Psychologist or Provider
            $psychUser = $b->psychologist_user_id ? ($users[$b->psychologist_user_id] ?? null) : null;
            $provider = $b->provider_id ? ($providers[$b->provider_id] ?? null) : null;
            $providerUser = $provider ? ($users[$provider->user_id] ?? null) : null;

            $assignedPerson = $psychUser
                ? trim($psychUser->first_name . ' ' . $psychUser->last_name) . ($psychUser->role === 'AFFILIATE' ? ' (Affiliate)' : ' (Admin)')
                : ($providerUser
                    ? trim($providerUser->first_name . ' ' . $providerUser->last_name) . ($providerUser->role === 'AFFILIATE' ? ' (Affiliate)' : ' (Admin)')
                    : ($provider?->display_name ?: 'Clinic Specialist'));

            // Initial Booking & Payment Upload
            ActivityLog::log(
                $clientUser ?: ['id' => $b->user_id, 'name' => $clientName, 'role' => 'CLIENT'],
                'appointment_booked',
                'appointment',
                'Booked ' . $sName,
                "Client {$clientName} booked an appointment for {$sName} with {$assignedPerson} (Ref: {$b->reference}) and uploaded initial payment proof of ₱" . number_format((float) $b->amount_paid, 2),
                $b->reference,
                [
                    'client_name'   => $clientName,
                    'service_name'  => $sName,
                    'assigned_to'   => $assignedPerson,
                    'amount'        => (float) $b->amount_paid,
                    'payment_type'  => $b->payment_type,
                    'start_at'      => $b->start_at?->toIso8601String(),
                ],
                $b->created_at
            );

            // Payment verification/rejection
            if ($b->reviewed_at && in_array($b->status, ['verified', 'rejected'], true)) {
                $reviewer = $users[$b->reviewed_by] ?? null;
                $reviewerName = $reviewer ? trim($reviewer->first_name . ' ' . $reviewer->last_name) : 'Admin';
                $reviewerRole = $reviewer?->role ?: 'ADMIN';

                if ($b->status === 'verified') {
                    ActivityLog::log(
                        $reviewer ?: ['id' => $b->reviewed_by, 'name' => $reviewerName, 'role' => $reviewerRole],
                        'payment_verified',
                        'payment',
                        'Verified Initial Payment',
                        "{$reviewerName} ({$reviewerRole}) verified initial payment of ₱" . number_format((float) $b->amount_paid, 2) . " for client {$clientName} (Ref: {$b->reference})",
                        $b->reference,
                        [
                            'client_name' => $clientName,
                            'amount'      => (float) $b->amount_paid,
                            'reviewer'    => $reviewerName,
                        ],
                        $b->reviewed_at
                    );
                } else {
                    ActivityLog::log(
                        $reviewer ?: ['id' => $b->reviewed_by, 'name' => $reviewerName, 'role' => $reviewerRole],
                        'payment_rejected',
                        'payment',
                        'Rejected Initial Payment',
                        "{$reviewerName} ({$reviewerRole}) rejected payment for client {$clientName} (Ref: {$b->reference}). Reason: " . ($b->reject_reason ?: 'Invalid receipt'),
                        $b->reference,
                        [
                            'client_name' => $clientName,
                            'reason'      => $b->reject_reason,
                            'reviewer'    => $reviewerName,
                        ],
                        $b->reviewed_at
                    );
                }
            }

            // Appointment Approval / Decline
            if ($b->approved_at && in_array($b->approval_status, ['approved', 'declined'], true)) {
                $approver = $users[$b->approved_by] ?? null;
                $approverName = $approver ? trim($approver->first_name . ' ' . $approver->last_name) : 'Admin';
                $approverRole = $approver?->role ?: 'ADMIN';

                if ($b->approval_status === 'approved') {
                    ActivityLog::log(
                        $approver ?: ['id' => $b->approved_by, 'name' => $approverName, 'role' => $approverRole],
                        'appointment_approved',
                        'appointment',
                        'Approved Appointment',
                        "{$approverName} ({$approverRole}) approved appointment for {$clientName} on " . ($b->start_at ? $b->start_at->format('M d, Y h:i A') : 'scheduled date') . " (Ref: {$b->reference})",
                        $b->reference,
                        [
                            'client_name'  => $clientName,
                            'service_name' => $sName,
                            'approver'     => $approverName,
                            'start_at'     => $b->start_at?->toIso8601String(),
                        ],
                        $b->approved_at
                    );
                } else {
                    ActivityLog::log(
                        $approver ?: ['id' => $b->approved_by, 'name' => $approverName, 'role' => $approverRole],
                        'appointment_declined',
                        'appointment',
                        'Declined Appointment',
                        "{$approverName} ({$approverRole}) declined appointment for {$clientName} (Ref: {$b->reference}). Reason: " . ($b->decline_reason ?: 'Declined by clinic'),
                        $b->reference,
                        [
                            'client_name' => $clientName,
                            'reason'      => $b->decline_reason,
                            'approver'    => $approverName,
                        ],
                        $b->approved_at
                    );
                }
            }

            // Rescheduled
            if ($b->rescheduled_at) {
                ActivityLog::log(
                    $clientUser ?: ['id' => $b->user_id, 'name' => $clientName, 'role' => 'CLIENT'],
                    'appointment_rescheduled',
                    'appointment',
                    'Rescheduled Appointment',
                    "Client {$clientName} rescheduled appointment (Ref: {$b->reference}) to " . ($b->start_at ? $b->start_at->format('M d, Y h:i A') : 'new date'),
                    $b->reference,
                    [
                        'client_name' => $clientName,
                        'new_start'   => $b->start_at?->toIso8601String(),
                    ],
                    $b->rescheduled_at
                );
            }

            // Cancelled
            if ($b->cancelled_at) {
                ActivityLog::log(
                    $clientUser ?: ['id' => $b->user_id, 'name' => $clientName, 'role' => 'CLIENT'],
                    'appointment_cancelled',
                    'appointment',
                    'Cancelled Appointment',
                    "Client {$clientName} cancelled appointment (Ref: {$b->reference}). Reason: " . ($b->cancel_reason ?: 'No reason specified'),
                    $b->reference,
                    [
                        'client_name' => $clientName,
                        'reason'      => $b->cancel_reason,
                    ],
                    $b->cancelled_at
                );
            }

            // Completed
            if ($b->completed_at) {
                ActivityLog::log(
                    ['id' => null, 'name' => 'System', 'role' => 'SYSTEM'],
                    'appointment_completed',
                    'appointment',
                    'Completed Session',
                    "Appointment session completed for client {$clientName} ({$sName}, Ref: {$b->reference})",
                    $b->reference,
                    [
                        'client_name'  => $clientName,
                        'service_name' => $sName,
                    ],
                    $b->completed_at
                );
            }
        }

        // 2. Balance Payments
        $balanceRows = DB::table('balance_payments')->get();
        foreach ($balanceRows as $bal) {
            $bp = $bookings->firstWhere('id', $bal->booking_payment_id);
            $clientUser = $bp ? ($users[$bp->user_id] ?? null) : null;
            $clientName = $clientUser ? trim($clientUser->first_name . ' ' . $clientUser->last_name) : 'Client';
            $ref = $bp?->reference ?: 'TF-BALANCE';

            ActivityLog::log(
                $clientUser ?: ['id' => $bp?->user_id, 'name' => $clientName, 'role' => 'CLIENT'],
                'balance_submitted',
                'payment',
                'Submitted Balance Payment',
                "Client {$clientName} submitted remaining balance payment of ₱" . number_format((float) $bal->amount, 2) . " (Ref: {$ref})",
                $ref,
                ['amount' => (float) $bal->amount, 'client_name' => $clientName],
                $bal->created_at
            );

            if ($bal->reviewed_at && in_array($bal->status, ['verified', 'rejected'], true)) {
                $rev = $users[$bal->reviewed_by] ?? null;
                $revName = $rev ? trim($rev->first_name . ' ' . $rev->last_name) : 'Admin';
                $revRole = $rev?->role ?: 'ADMIN';

                ActivityLog::log(
                    $rev ?: ['id' => $bal->reviewed_by, 'name' => $revName, 'role' => $revRole],
                    $bal->status === 'verified' ? 'balance_verified' : 'balance_rejected',
                    'payment',
                    $bal->status === 'verified' ? 'Verified Balance Payment' : 'Rejected Balance Payment',
                    "{$revName} ({$revRole}) " . ($bal->status === 'verified' ? 'verified' : 'rejected') . " balance payment of ₱" . number_format((float) $bal->amount, 2) . " for Ref: {$ref}",
                    $ref,
                    ['amount' => (float) $bal->amount, 'reviewer' => $revName],
                    $bal->reviewed_at
                );
            }
        }

        // 3. Provider Availability Slots
        $availabilities = ProviderAvailability::all();
        foreach ($availabilities as $av) {
            $prov = $providers[$av->provider_id] ?? null;
            $provUser = $prov ? ($users[$prov->user_id] ?? null) : null;
            $creatorName = $provUser ? trim($provUser->first_name . ' ' . $provUser->last_name) : ($prov?->display_name ?: 'Specialist');
            $creatorRole = $av->created_by_role ? strtoupper($av->created_by_role) : ($provUser?->role ?: 'ADMIN');

            ActivityLog::log(
                $provUser ?: ['id' => $prov?->user_id, 'name' => $creatorName, 'role' => $creatorRole],
                'schedule_created',
                'schedule',
                'Added Schedule Slot',
                "{$creatorName} ({$creatorRole}) created availability schedule for " . ($av->start_at ? $av->start_at->format('M d, Y h:i A') : 'date') . " - " . ($av->end_at ? $av->end_at->format('h:i A') : 'time'),
                null,
                [
                    'provider_name' => $creatorName,
                    'start_at'      => $av->start_at?->toIso8601String(),
                    'end_at'        => $av->end_at?->toIso8601String(),
                ],
                $av->created_at
            );
        }
    }
}

