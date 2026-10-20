<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the admissions team: a family booked a visit. Carries the guardian's
 * name only; contact details stay in the portal.
 */
class SchoolVisitStaffMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $schoolName,
        public readonly string $when,
        public readonly string $guardianName,
        public readonly int $remaining,
        public readonly string $leadUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->schoolName}: ახალი ვიზიტის ჯავშანი");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p><strong>'.e($this->guardianName).'</strong> დაჯავშნა ვიზიტი: '.e($this->when).'.</p>'
            .'<p>დარჩენილი ადგილები: '.$this->remaining.'.</p>'
            .'<p><a href="'.e($this->leadUrl).'">მიღების პროცესში ნახვა</a></p>');
    }
}
