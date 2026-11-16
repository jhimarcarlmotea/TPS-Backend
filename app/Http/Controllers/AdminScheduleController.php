<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Provider;
use App\Models\ProviderAvailability;
use App\Models\SlotHold;
use Carbon\Carbon;
use App\Models\BookingPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminScheduleController extends Controller
{
    protected string $role = 'admin';
    protected function providerId(Request $request): int
{
    $user = $request->user();

   
    $provider = Provider::firstOrCreate(
        ['user_id' => $user->id],
        [
            'code'         => 'P' . str_pad((string) Provider::count(), 3, '0', STR_PAD_LEFT),
            'display_name' => trim($user->first_name . ' ' . $user->last_name),
        ]
    );

    return $provider->id;
}

    
    protected function format(ProviderAvailability $s): array
{
    
    $base = BookingPayment::where('provider_id', $s->provider_id)
        ->where('status', '!=', 'rejected')
        ->whereNull('cancelled_at')
        ->where('start_at', '>=', $s->start_at)
        ->where('end_at', '<=', $s->end_at);

    return [
        'id'         => $s->id,
        'date'       => $s->start_at->format('Y-m-d'),
        'start_time' => $s->start_at->format('H:i'),
        'end_time'   => $s->end_at->format('H:i'),
        'status'     => $s->status,
        'created_by' => $s->created_by_role,
        'booked'     => (clone $base)->where('approval_status', 'approved')->count(),
        'requested'  => (clone $base)->where('approval_status', 'pending')->count(),
    ];
}

    // GET /api/admin/schedule?month=2026-10
    public function index(Request $request)
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $providerId = $this->providerId($request);

        $start = Carbon::createFromFormat('!Y-m', $data['month'])->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        $slots = ProviderAvailability::where('provider_id', $providerId)
            ->whereBetween('start_at', [$start, $end])
            ->orderBy('start_at')
            ->get()
            ->map(fn ($s) => $this->format($s));

        $from = Carbon::today();
        $to   = Carbon::today()->addDays(30)->endOfDay();

            $stats = [
        'open_slots' => ProviderAvailability::where('provider_id', $providerId)
            ->where('status', 'available')
            ->whereBetween('start_at', [$from, $to])->count(),
        'booked' => BookingPayment::where('provider_id', $providerId)
            ->where('status', '!=', 'rejected')
            ->where('approval_status', 'approved')
            ->whereBetween('start_at', [$from, $to])->count(),
        'pending' => BookingPayment::where('provider_id', $providerId)
            ->where('status', '!=', 'rejected')
            ->where('approval_status', 'pending')
            ->whereBetween('start_at', [$from, $to])->count(),
        'not_available' => ProviderAvailability::where('provider_id', $providerId)
            ->where('status', 'unavailable')
            ->whereBetween('start_at', [$from, $to])->count(),
    ];

        return response()->json(['slots' => $slots, 'stats' => $stats]);
    }

    // POST /api/admin/schedule/slots
    public function store(Request $request)
    {
        $data = $request->validate([
            'dates'      => ['sometimes', 'array', 'min:1'],
            'dates.*'    => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'date'       => ['required_without:dates', 'date_format:Y-m-d', 'after_or_equal:today'],
            'status'     => ['required', 'in:available,blocked,not_available'],
            'start_time' => ['nullable', 'required_if:status,available', 'required_if:status,blocked', 'date_format:H:i'],
            'end_time'   => ['nullable', 'required_if:status,available', 'required_if:status,blocked', 'date_format:H:i', 'after:start_time'],
        ]);

        $isBulk = array_key_exists('dates', $data);
        $dates = array_values(array_unique($data['dates'] ?? [$data['date']]));
        $providerId = $this->providerId($request);
        $created = DB::transaction(function () use ($data, $dates, $providerId, $request) {
            foreach ($dates as $date) {
                $dayStart = Carbon::parse($date)->startOfDay();
                $dayEnd   = Carbon::parse($date)->endOfDay();
                $base = ProviderAvailability::where('provider_id', $providerId)
                    ->whereBetween('start_at', [$dayStart, $dayEnd]);

                if ($data['status'] === 'not_available') {
                    continue;
                }

                $newStart = $date . ' ' . $data['start_time'] . ':00';
                $newEnd   = $date . ' ' . $data['end_time'] . ':00';

                if ($data['status'] === 'available') {
                    $overlap = (clone $base)
                        ->whereIn('status', ['available', 'unavailable'])
                        ->where('start_at', '<', $newEnd)
                        ->where('end_at', '>', $newStart)
                        ->first();

                    if ($overlap) {
                        $message = $overlap->status === 'unavailable'
                            ? "The selected time overlaps a time marked not available on {$date}."
                            : "The selected time overlaps an existing slot on {$date}.";
                        throw ValidationException::withMessages(['dates' => $message]);
                    }
                    continue;
                }

                $coveredSlot = (clone $base)
                    ->where('status', 'available')
                    ->where('start_at', '<=', $newStart)
                    ->where('end_at', '>=', $newEnd)
                    ->exists();
                if (!$coveredSlot) {
                    throw ValidationException::withMessages([
                        'dates' => "The selected not-available time must be inside an available slot on {$date}.",
                    ]);
                }

                $blockOverlap = (clone $base)
                    ->where('status', 'unavailable')
                    ->where('start_at', '<', $newEnd)
                    ->where('end_at', '>', $newStart)
                    ->exists();
                if ($blockOverlap) {
                    throw ValidationException::withMessages([
                        'dates' => "The selected time already overlaps a time marked not available on {$date}.",
                    ]);
                }

                $hasBooking = BookingPayment::where('provider_id', $providerId)
                    ->whereIn('status', ['to_verify', 'verified'])
                    ->where('approval_status', '!=', 'declined')
                    ->whereNull('cancelled_at')
                    ->where('start_at', '<', $newEnd)
                    ->where('end_at', '>', $newStart)
                    ->exists();
                if ($hasBooking) {
                    throw ValidationException::withMessages([
                        'dates' => "The selected time has an online appointment and cannot be blocked on {$date}.",
                    ]);
                }

                $hasHold = SlotHold::active()
                    ->where('provider_id', $providerId)
                    ->overlapping($newStart, $newEnd)
                    ->exists();
                if ($hasHold) {
                    throw ValidationException::withMessages([
                        'dates' => "The selected time is currently held for an online booking on {$date}.",
                    ]);
                }
            }

            $slots = [];
            foreach ($dates as $date) {
                $dayStart = Carbon::parse($date)->startOfDay();
                $dayEnd   = Carbon::parse($date)->endOfDay();
                $base = ProviderAvailability::where('provider_id', $providerId)
                    ->whereBetween('start_at', [$dayStart, $dayEnd]);

                if ($data['status'] === 'not_available') {
                    (clone $base)->delete();
                    $slot = ProviderAvailability::create([
                        'provider_id' => $providerId,
                        'start_at'    => $date . ' 00:00:00',
                        'end_at'      => $date . ' 23:59:00',
                        'status'      => 'unavailable',
                        'created_by_role' => $this->role,
                    ]);
                } elseif ($data['status'] === 'blocked') {
                    $blockStart = Carbon::parse($date . ' ' . $data['start_time']);
                    $blockEnd = Carbon::parse($date . ' ' . $data['end_time']);
                    $availableSlot = (clone $base)
                        ->where('status', 'available')
                        ->where('start_at', '<=', $blockStart)
                        ->where('end_at', '>=', $blockEnd)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $availableStart = $availableSlot->start_at->copy();
                    $availableEnd = $availableSlot->end_at->copy();
                    $createdBy = $availableSlot->created_by_role;
                    $availableSlot->delete();

                    if ($availableStart->lt($blockStart)) {
                        $slots[] = ProviderAvailability::create([
                            'provider_id' => $providerId,
                            'start_at' => $availableStart,
                            'end_at' => $blockStart,
                            'status' => 'available',
                            'created_by_role' => $createdBy,
                        ]);
                    }

                    $slot = ProviderAvailability::create([
                        'provider_id' => $providerId,
                        'start_at' => $blockStart,
                        'end_at' => $blockEnd,
                        'status' => 'unavailable',
                        'created_by_role' => $this->role,
                    ]);

                    if ($blockEnd->lt($availableEnd)) {
                        $slots[] = ProviderAvailability::create([
                            'provider_id' => $providerId,
                            'start_at' => $blockEnd,
                            'end_at' => $availableEnd,
                            'status' => 'available',
                            'created_by_role' => $createdBy,
                        ]);
                    }

                    $u = $request->user();
                    ActivityLog::log(
                        $u,
                        'schedule_blocked',
                        'schedule',
                        'Blocked Schedule Time',
                        "{$u->first_name} {$u->last_name} ({$u->role}) marked {$date} {$data['start_time']} - {$data['end_time']} not available for a walk-in patient",
                        null,
                        ['date' => $date, 'start_time' => $data['start_time'], 'end_time' => $data['end_time']]
                    );
                } else {
                    $slot = ProviderAvailability::create([
                        'provider_id' => $providerId,
                        'start_at'    => $date . ' ' . $data['start_time'] . ':00',
                        'end_at'      => $date . ' ' . $data['end_time'] . ':00',
                        'status'      => 'available',
                        'created_by_role' => $this->role,
                    ]);

                    $u = $request->user();
                    ActivityLog::log(
                        $u,
                        'schedule_created',
                        'schedule',
                        'Added Schedule Slot',
                        "{$u->first_name} {$u->last_name} ({$u->role}) added an available schedule slot on {$date} {$data['start_time']} - {$data['end_time']}",
                        null,
                        ['date' => $date, 'start_time' => $data['start_time'], 'end_time' => $data['end_time']]
                    );
                }

                $slots[] = $slot;
            }

            return $slots;
        });

        $formatted = array_map(fn ($slot) => $this->format($slot), $created);
        return $isBulk || $data['status'] === 'blocked'
            ? response()->json(['slots' => $formatted], 201)
            : response()->json($formatted[0], 201);
    }

    public function restoreBlockedTime(Request $request, $id)
    {
        $slot = ProviderAvailability::where('provider_id', $this->providerId($request))
            ->findOrFail($id);

        if ($slot->start_at->lt(Carbon::today())) {
            return response()->json(['message' => 'Past dates cannot be changed.'], 422);
        }

        if ($slot->status !== 'unavailable'
            || ($slot->start_at->format('H:i') === '00:00' && $slot->end_at->format('H:i') === '23:59')) {
            return response()->json(['message' => 'Only a blocked time slot can be restored.'], 422);
        }

        $restored = DB::transaction(function () use ($slot, $request) {
            $slot = ProviderAvailability::where('provider_id', $slot->provider_id)
                ->lockForUpdate()
                ->findOrFail($slot->id);
            $start = $slot->start_at->copy();
            $end = $slot->end_at->copy();
            $mergedIds = [$slot->id];

            do {
                $previousStart = $start->copy();
                $previousEnd = $end->copy();
                $adjacent = ProviderAvailability::where('provider_id', $slot->provider_id)
                    ->where('status', 'available')
                    ->whereDate('start_at', $slot->start_at->toDateString())
                    ->where('start_at', '<=', $end)
                    ->where('end_at', '>=', $start)
                    ->lockForUpdate()
                    ->get();

                foreach ($adjacent as $available) {
                    $mergedIds[] = $available->id;
                    if ($available->start_at->lt($start)) $start = $available->start_at->copy();
                    if ($available->end_at->gt($end)) $end = $available->end_at->copy();
                }
            } while (!$start->equalTo($previousStart) || !$end->equalTo($previousEnd));

            ProviderAvailability::whereIn('id', array_diff(array_unique($mergedIds), [$slot->id]))->delete();
            $slot->forceFill([
                'start_at' => $start,
                'end_at' => $end,
                'status' => 'available',
            ])->save();

            $u = $request->user();
            ActivityLog::log(
                $u,
                'schedule_restored',
                'schedule',
                'Restored Schedule Time',
                "{$u->first_name} {$u->last_name} ({$u->role}) restored availability on {$start->format('M d, Y h:i A')} - {$end->format('h:i A')}",
                null,
                ['start_at' => $start->toIso8601String(), 'end_at' => $end->toIso8601String()]
            );

            return $slot;
        });

        return response()->json(['slot' => $this->format($restored)]);
    }

    // DELETE /api/admin/schedule/slots/{id}
    public function destroy(Request $request, $id)
{
    $slot = ProviderAvailability::where('provider_id', $this->providerId($request))
        ->findOrFail($id);

    if ($slot->start_at->lt(Carbon::today())) {
        return response()->json(['message' => 'Past dates cannot be changed.'], 422);
    }

    $u = $request->user();
    $slotTime = $slot->start_at->format('M d, Y h:i A');
    $slot->delete();

    ActivityLog::log(
        $u,
        'schedule_deleted',
        'schedule',
        'Deleted Schedule Slot',
        "{$u->first_name} {$u->last_name} ({$u->role}) deleted schedule slot on {$slotTime}",
        null
    );

    return response()->json(['message' => 'Deleted']);
}
}