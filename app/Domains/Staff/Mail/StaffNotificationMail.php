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
 * flow — it is not translated per recipient. A message with a link gets a button to it.
 */
final class StaffNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string|null  $actionUrl  an absolute address on the tenant's host, or null for a message without a link
     */
    public function __construct(
        public string $recipientName,
        public string $messageBody,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
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
                'actionUrl'     => $this->actionUrl,
                'actionLabel'   => $this->actionLabel ?? __('staff.notifications.open'),
            ],
        );
    }
}
