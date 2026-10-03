<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a teacher they cover a lesson. Sent only after the assignment
 * transaction commits; carries the class/subject/time, never students.
 */
class SubstituteAssignedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $subjectName,
        public readonly string $className,
        public readonly string $date,
        public readonly string $time,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "ჩანაცვლება: {$this->className}, {$this->date}");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>თქვენ დაგენიშნათ ჩანაცვლება: <strong>'.e($this->subjectName).'</strong>, '.e($this->className).'</p>'
            .'<p>'.e($this->date).' · '.e($this->time).'</p>'
            .'<p>გეგმა და მასალები ხელმისაწვდომია პორტალში, მასწავლებლის სამუშაო სივრცეში.</p>');
    }
}
