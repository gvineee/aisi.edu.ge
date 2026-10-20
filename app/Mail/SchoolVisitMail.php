<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the family that booked a school visit: confirmation (with the signed
 * cancel link), the day-before reminder, or the school's cancellation.
 */
class SchoolVisitMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public const KIND_CONFIRMED = 'confirmed';

    public const KIND_REMINDER = 'reminder';

    public const KIND_CANCELLED_BY_SCHOOL = 'cancelled_by_school';

    public function __construct(
        public readonly string $kind,
        public readonly string $schoolName,
        public readonly string $when,
        public readonly ?string $location,
        public readonly ?string $cancelUrl = null,
        public readonly ?string $reason = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->kind) {
            self::KIND_REMINDER => 'ვიზიტის შეხსენება',
            self::KIND_CANCELLED_BY_SCHOOL => 'ვიზიტი გაუქმდა',
            default => 'ვიზიტი დაჯავშნილია',
        };

        return new Envelope(subject: "{$this->schoolName}: {$subject}");
    }

    public function content(): Content
    {
        $place = '<p><strong>'.e($this->when).'</strong>'.($this->location ? ' · '.e($this->location) : '').'</p>';

        $body = match ($this->kind) {
            self::KIND_REMINDER => '<p>შეგახსენებთ სკოლაში ვიზიტს:</p>'.$place
                .'<p>თუ ვერ მოდიხართ, გთხოვთ, გააუქმოთ ჯავშანი დადასტურების წერილის ბმულით ან დაგვიკავშირდეთ.</p>',
            self::KIND_CANCELLED_BY_SCHOOL => '<p>სამწუხაროდ, სკოლაში ვიზიტი გაუქმდა:</p>'.$place
                .($this->reason ? '<p>მიზეზი: '.e($this->reason).'</p>' : '')
                .'<p>ახალი დროის არჩევა შეგიძლიათ სკოლის საიტზე, „ვიზიტის დაგეგმვა“.</p>',
            default => '<p>გმადლობთ — სკოლაში ვიზიტი დაჯავშნილია:</p>'.$place
                .($this->cancelUrl ? '<p>თუ ვერ მოდიხართ, გთხოვთ, გააუქმოთ ჯავშანი, რომ ადგილი სხვა ოჯახს დარჩეს: <a href="'.e($this->cancelUrl).'">ჯავშნის გაუქმება</a></p>' : ''),
        };

        return new Content(htmlString: $body);
    }
}
