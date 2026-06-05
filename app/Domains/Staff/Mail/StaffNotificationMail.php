<?php

declare(strict_types=1);

namespace App\Domains\Staff\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email carrying a staff escalation notification raised by a `notify_staff` node.
 *
 * The body is the literal, already-rendered admin-language text authored in the
 * flow — it is not translated per recipient.
 */
final class StaffNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $messageBody,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('staff.notifications.escalation_subject', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.staff.notification',
            with: [
                'recipientName' => $this->recipientName,
                'messageBody'   => $this->messageBody,
            ],
        );
    }
}
