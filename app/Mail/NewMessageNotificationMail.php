<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Minimal email nudge for a new portal message. It never contains the
 * message body — sensitive health/financial detail stays in the portal
 * (CLAUDE-PLATFORM-MODULES §4); only the school name, the subject and a
 * link to sign in.
 */
class NewMessageNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $schoolName,
        public readonly string $conversationSubject,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->schoolName}: ახალი შეტყობინება");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>პორტალში ახალი შეტყობინება გაქვთ: <strong>'.e($this->conversationSubject).'</strong></p>'
            .'<p><a href="'.e($this->url).'">შეტყობინების ნახვა</a></p>');
    }
}
