<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Invitation $invitation,
        public string $token,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to Kanvigo'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $acceptUrl = URL::temporarySignedRoute(
            'invitation.accept',
            $this->invitation->expires_at,
            ['invitation' => $this->invitation->getKey(), 'token' => $this->token],
        );

        return new Content(
            markdown: 'emails.invitation',
            with: [
                'acceptUrl' => $acceptUrl,
                'inviterName' => $this->invitation->inviter->name,
            ],
        );
    }
}
