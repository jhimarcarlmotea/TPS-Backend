<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Casts\Attribute;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $appends = ['avatar_url'];

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'background',
        'password',
        'login_provider',
        'google_id',
        'facebook_id',
        'avatar_path',
        'verification_code',
        'verification_expires_at',
        'email_verified_at',
        'pending_email',
        'pending_email_code',
        'pending_email_expires_at',
        'pending_email_attempts',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'verification_code',
        'verification_expires_at',
        'pending_email_code',
    ];

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->avatar_path
                ? url('storage/' . $this->avatar_path)
                : null
        );
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'verification_expires_at' => 'datetime',
        ];
        
    }
    protected $casts = [
    'email_verified_at' => 'datetime',
    'password' => 'hashed',

    'pending_email_expires_at' => 'datetime',
    'pending_email_attempts'   => 'integer',
];
}
