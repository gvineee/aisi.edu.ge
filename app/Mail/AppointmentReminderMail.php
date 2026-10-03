<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reminder for a booked parent–teacher meeting. Carries only the time,
 * place and the other party's name — the booking note stays in the portal.
 */
class AppointmentReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $schoolName,
        public readonly string $withName,
        public readonly string $when,
        public readonly ?string $location,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->schoolName}: შეხვედრის შეხსენება");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>შეგახსენებთ შეხვედრას: <strong>'.e($this->withName).'</strong></p>'
            .'<p>'.e($this->when).($this->location ? ' · '.e($this->location) : '').'</p>'
            .'<p>დეტალები და გაუქმება — პორტალში, „შეხვედრები“.</p>');
    }
}
