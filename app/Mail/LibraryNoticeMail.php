<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To guardians: a reserved book is waiting at the library, or a borrowed
 * book is overdue. Title, student and date only.
 */
class LibraryNoticeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public const KIND_RESERVATION_READY = 'reservation_ready';

    public const KIND_OVERDUE = 'overdue';

    public function __construct(
        public readonly string $kind,
        public readonly string $schoolName,
        public readonly string $title,
        public readonly string $studentName,
        public readonly string $date,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->kind === self::KIND_OVERDUE ? 'წიგნის დაბრუნების ვადა გავიდა' : 'დაჯავშნილი წიგნი გელოდებათ';

        return new Envelope(subject: "{$this->schoolName}: {$subject}");
    }

    public function content(): Content
    {
        $book = '<strong>'.e($this->title).'</strong> ('.e($this->studentName).')';

        return new Content(htmlString: $this->kind === self::KIND_OVERDUE
            ? '<p>ბიბლიოთეკის წიგნის '.$book.' დაბრუნების ვადა იყო '.e($this->date).'.</p><p>გთხოვთ, დააბრუნოთ ბიბლიოთეკაში ან მიმართოთ ბიბლიოთეკარს ვადის გასაგრძელებლად.</p>'
            : '<p>დაჯავშნილი წიგნი '.$book.' ბიბლიოთეკაში გელოდებათ.</p><p>გადადებულია '.e($this->date).'-მდე; შემდეგ რიგში მყოფს გადაეცემა.</p>');
    }
}
