<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To a staff member asked to read a published document version. Title,
 * version and link only — the file stays in the portal.
 */
class DocumentAcknowledgementMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $schoolName,
        public readonly string $title,
        public readonly int $versionOrdinal,
        public readonly ?string $dueOn,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->schoolName}: გთხოვთ, გაეცნოთ დოკუმენტს");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>გთხოვთ, გაეცნოთ დოკუმენტს <strong>'.e($this->title).'</strong> (ვერსია '.$this->versionOrdinal.')'
            .($this->dueOn ? ', '.e($this->dueOn).'-მდე' : '').'.</p>'
            .'<p>წაკითხვის შემდეგ დოკუმენტის გვერდზე დააჭირეთ „გავეცანი“: <a href="'.e($this->url).'">დოკუმენტის გახსნა</a></p>');
    }
}
