<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A new invoice or an overdue reminder for a guardian with financial
 * access. Carries only the number, amount and due date — line items and
 * payment history stay in the portal.
 */
class InvoiceNoticeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly bool $reminder,
        public readonly string $schoolName,
        public readonly string $studentName,
        public readonly string $invoiceNumber,
        public readonly string $amount,
        public readonly string $dueOn,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reminder
            ? "{$this->schoolName}: გადახდის შეხსენება — ინვოისი № {$this->invoiceNumber}"
            : "{$this->schoolName}: ახალი ინვოისი № {$this->invoiceNumber}");
    }

    public function content(): Content
    {
        $lead = $this->reminder
            ? 'გახსენებთ, რომ ინვოისის გადახდის ვადა გასულია ან ახლოვდება.'
            : 'გამოწერილია ახალი ინვოისი.';

        return new Content(htmlString: '<p>'.e($lead).'</p>'
            .'<p>მოსწავლე: <strong>'.e($this->studentName).'</strong><br>'
            .'ინვოისი № '.e($this->invoiceNumber).'<br>'
            .'გადასახდელი: <strong>'.e($this->amount).'</strong><br>'
            .'ვადა: '.e($this->dueOn).'</p>'
            .'<p>დეტალები და გადახდის რეკვიზიტები — პორტალში: <a href="'.e($this->url).'">'.e($this->url).'</a></p>'
            .'<p>თუ უკვე გადაიხადეთ, ეს წერილი უგულებელყავით — ბუღალტერია ჩარიცხვას ხელით ასახავს.</p>');
    }
}
