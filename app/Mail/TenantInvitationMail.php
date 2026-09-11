<?php

namespace App\Mail;

use App\Domain\Tenancy\Models\TenantInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invite link is the only credential in this email — never the
 * invitee's future password, and never a raw session/auth token beyond
 * the single-use invitation token itself (CLAUDE.md invariant #7's "no
 * token/password in logs" concern applies to mail bodies too, so keep this
 * template minimal).
 */
class TenantInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TenantInvitation $invitation,
        public readonly string $tenantName,
        public readonly string $acceptUrl,
        public readonly string $roleLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "მოწვევა — {$this->tenantName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.tenant-invitation',
            with: [
                'tenantName' => $this->tenantName,
                'roleLabel' => $this->roleLabel,
                'acceptUrl' => $this->acceptUrl,
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
