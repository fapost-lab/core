<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domains\Presale\Models\PreSaleRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class NewPresaleRequest extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly PreSaleRequest $presaleRequest,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('landing.email.subject', ['name' => $this->presaleRequest->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.presale-request',
            with: [
                'presale' => $this->presaleRequest,
            ],
        );
    }
}
