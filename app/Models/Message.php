<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    // Specify which columns may be saved.
    protected $fillable = [
        'sender_id',
        'receiver_id',
        'appointment_id',
        'message',
    ];

    // Relationship: a message belongs to a sender (User).
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    // Relationship: a message belongs to a receiver (User).
    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}