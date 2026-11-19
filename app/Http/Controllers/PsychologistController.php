<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PublicStorageUrl;
use App\Support\TpsCache;

class PsychologistController extends Controller
{
    /**
     * Public list of all active ADMIN and AFFILIATE users
     * for psychologist selection in Book Session (Psychotherapy only).
     */
    public function index()
    {
        $psychologists = TpsCache::remember('directory', 'psychologists:active', 300, fn () => User::whereIn('role', ['ADMIN', 'AFFILIATE'])
            ->where('is_active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($u) => [
                'id'         => $u->id,
                'first_name' => $u->first_name,
                'last_name'  => $u->last_name,
                'name'       => trim($u->first_name . ' ' . $u->last_name),
                'role'       => $u->role,
                'title'      => $u->role === 'ADMIN' ? 'Clinical Director & Lead Psychologist' : 'Licensed Psychologist & Affiliate',
                'email'      => $u->email,
                'phone'      => $u->phone,
                'avatar_url' => $u->avatar_url
                    ?? ($u->avatar_path ? PublicStorageUrl::fromPath((string) $u->avatar_path) : null),
            ])
            ->values());

        return response()->json(['psychologists' => $psychologists]);
    }

    public function show($id)
    {
        return response()->json(TpsCache::remember('directory', 'psychologist:' . (int) $id, 300, function () use ($id) {
            $u = User::whereIn('role', ['ADMIN', 'AFFILIATE'])
                ->where('is_active', true)
                ->findOrFail($id);

            return [
            'psychologist' => [
                'id'         => $u->id,
                'first_name' => $u->first_name,
                'last_name'  => $u->last_name,
                'name'       => trim($u->first_name . ' ' . $u->last_name),
                'role'       => $u->role,
                'title'      => $u->role === 'ADMIN' ? 'Clinical Director & Lead Psychologist' : 'Licensed Psychologist & Affiliate',
                'email'      => $u->email,
                'phone'      => $u->phone,
                'avatar_url' => $u->avatar_url
                    ?? ($u->avatar_path ? PublicStorageUrl::fromPath((string) $u->avatar_path) : null),
            ]
            ];
        }));
    }
}