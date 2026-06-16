<?php

namespace App\Mail;

use App\Models\StaffInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly StaffInvitation $invitation)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'ve been invited to manage ' . $this->invitation->agency->agency_name . ' on Navigo Console',
        );
    }

    public function content(): Content
    {
        $acceptUrl = rtrim(config('app.frontend_url', config('app.url')), '/') . '/accept-invite/' . $this->invitation->token;

        return new Content(
            markdown: 'emails.staff-invitation',
            with: [
                'agencyName' => $this->invitation->agency->agency_name,
                'role'       => $this->invitation->role,
                'acceptUrl'  => $acceptUrl,
                'expiresAt'  => $this->invitation->expires_at->format('D, d M Y'),
            ],
        );
    }
}
