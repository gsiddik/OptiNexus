<?php

namespace App\Mail;

use App\Models\UserInvitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The invitation e-mail. The link is the only place the token ever appears; it is built from the raw token handed to the job, never read back.
 */
class UserInvitationMail extends Mailable
{
    public function __construct(public readonly UserInvitation $invitation, public readonly string $link)
    {
        $this->locale($invitation->locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('invitations.mail.subject', [
            'application' => $this->invitation->application->name,
            'tenant' => $this->invitation->tenant->name,
        ], $this->invitation->locale));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.invitation',
            text: 'mail.invitation-text',
            with: [
                'link' => $this->link,
                'inviter' => $this->invitation->inviter?->name,
                'application' => $this->invitation->application->name,
                'tenant' => $this->invitation->tenant->name,
                'name' => $this->invitation->name,
                'expiresAt' => $this->invitation->expires_at->copy()->utc()->format('Y-m-d H:i').' UTC',
            ],
        );
    }
}
