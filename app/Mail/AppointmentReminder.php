<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $firstName,
        public string $serviceName,
        public string $appointmentDate,
        public string $startTime,
        public string $endTime,
        public ?string $psychologistName,
        public string $reference,
        public bool $isOneHourReminder = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->isOneHourReminder
            ? 'Your Threefold appointment is coming up in one hour'
            : 'Reminder: your Threefold appointment is within 24 hours';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.appointment-reminder');
    }
}
