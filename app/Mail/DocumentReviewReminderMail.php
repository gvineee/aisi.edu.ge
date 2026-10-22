<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To a review-chain reviewer (or their active delegate) whose step is due
 * within a day or overdue. Title, due date and link only.
 */
class DocumentReviewReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $schoolName,
        public readonly string $title,
        public readonly string $dueAt,
        public readonly bool $overdue,
        public readonly ?string $onBehalfOf,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->schoolName}: ".($this->overdue ? 'განხილვის ვადა გადაცილებულია' : 'დოკუმენტის განხილვის ვადა ახლოვდება'));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>დოკუმენტი <strong>'.e($this->title).'</strong> თქვენს განხილვას ელოდება'
            .($this->onBehalfOf ? ' ('.e($this->onBehalfOf).'-ის ნაცვლად)' : '')
            .'. ვადა: '.e($this->dueAt).($this->overdue ? ' — გადაცილებულია' : '').'.</p>'
            .'<p><a href="'.e($this->url).'">დოკუმენტის გახსნა</a></p>');
    }
}
