<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $fillable = [
        'user_id',
        'actor_name',
        'actor_role',
        'action',
        'category',
        'title',
        'description',
        'reference',
        'metadata',
        'ip_address',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Helper to write an activity log.
     *
     * @param  User|array|null  $actor
     */
    public static function log(
        $actor,
        string $action,
        string $category,
        string $title,
        string $description,
        ?string $reference = null,
        ?array $metadata = null,
        $timestamp = null
    ): self {
        $userId = null;
        $actorName = 'System';
        $actorRole = 'SYSTEM';

        if ($actor instanceof User) {
            $userId = $actor->id;
            $actorName = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: ($actor->email ?? 'User');
            $actorRole = strtoupper((string) ($actor->role ?? 'USER'));
        } elseif (is_array($actor)) {
            $userId = $actor['id'] ?? null;
            $actorName = $actor['name'] ?? ($actor['actor_name'] ?? 'System');
            $actorRole = strtoupper((string) ($actor['role'] ?? ($actor['actor_role'] ?? 'SYSTEM')));
        } elseif (is_object($actor)) {
            $userId = $actor->id ?? null;
            $actorName = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: ($actor->name ?? 'User');
            $actorRole = strtoupper((string) ($actor->role ?? 'USER'));
        }

        $createdAt = $timestamp ? Carbon::parse($timestamp) : now();

        $ip = null;
        try {
            $ip = request()?->ip();
        } catch (\Throwable) {
            // ignore
        }

        $entry = new self([
            'user_id'    => $userId,
            'actor_name' => $actorName,
            'actor_role' => $actorRole,
            'action'     => $action,
            'category'   => $category,
            'title'      => $title,
            'description'=> $description,
            'reference'  => $reference,
            'metadata'   => $metadata,
            'ip_address' => $ip,
        ]);

        $entry->created_at = $createdAt;
        $entry->updated_at = $createdAt;
        $entry->save();

        return $entry;
    }
}

