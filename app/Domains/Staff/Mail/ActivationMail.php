<?php

declare(strict_types=1);

namespace App\Domains\Staff\Mail;

use App\Domains\Staff\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ActivationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $plainToken,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Invitation to :app', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.staff.activation',
            with: [
                'userName'      => $this->user->name,
                'activationUrl' => url('/activate?token=' . urlencode($this->plainToken)),
            ],
        );
    }
}
