<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    
protected $fillable = [
    'first_name',
    'last_name',
    'email',
    'phone',
    'password',
    'google_id',
    'facebook_id',
    'verification_code',
    'verification_expires_at',
    'email_verified_at',
    'role',
];

    protected $hidden = ['password'];

    protected $casts = [
        'password'  => 'hashed',
        'is_active' => 'boolean',
    ];
}