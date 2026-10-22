<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent synchronously (not queued): it may be reporting that the queue
 * itself is stuck.
 */
class OperationsAlertMail extends Mailable
{
    /**
     * @param  array<int, string>  $problems
     * @param  array<int, string>  $failedJobs
     */
    public function __construct(
        public readonly string $appName,
        public readonly array $problems,
        public readonly array $failedJobs,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->appName}: ფონური სამუშაოების გაფრთხილება");
    }

    public function content(): Content
    {
        $html = '';

        if ($this->problems !== []) {
            $html .= '<p><strong>პრობლემა:</strong></p><ul>'.implode('', array_map(fn (string $p) => '<li>'.e($p).'</li>', $this->problems)).'</ul>';
        }

        if ($this->failedJobs !== []) {
            $html .= '<p><strong>ჩავარდნილი ამოცანები ('.count($this->failedJobs).'):</strong></p><ul>'
                .implode('', array_map(fn (string $line) => '<li>'.e($line).'</li>', $this->failedJobs)).'</ul>'
                .'<p>დეტალები: <code>php artisan queue:failed</code>; ხელახლა გაშვება: <code>php artisan queue:retry all</code>.</p>';
        }

        return new Content(htmlString: $html);
    }
}
