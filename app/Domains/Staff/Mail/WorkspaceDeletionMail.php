<?php

declare(strict_types=1);

namespace App\Domains\Staff\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells an admin that the deletion of the workspace was scheduled (with when it starts, what is lost, and how to
 * cancel) or cancelled (and by whom). Times are UTC and say so: Core keeps no time zone per tenant.
 */
final class WorkspaceDeletionMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public const string SCHEDULED = 'scheduled';

    public const string CANCELLED = 'cancelled';

    public function __construct(
        public string $kind,
        public string $recipientName,
        public string $workspace,
        public string $requestedByName,
        public string $requestedByEmail,
        public string $requestedAt,
        public string $startsAt,
        public ?string $cancelledByName,
        public ?string $cancelledByEmail,
        public string $settingsUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(self::CANCELLED === $this->kind ? 'staff.workspace_deletion.cancelled.subject' : 'staff.workspace_deletion.scheduled.subject', ['workspace' => $this->workspace]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.staff.workspace-deletion',
            with: [
                'cancelled'        => self::CANCELLED === $this->kind,
                'recipientName'    => $this->recipientName,
                'workspace'        => $this->workspace,
                'requestedBy'      => $this->requestedByName . ' (' . $this->requestedByEmail . ')',
                'cancelledBy'      => null === $this->cancelledByName ? null : $this->cancelledByName . ' (' . $this->cancelledByEmail . ')',
                'requestedAtLabel' => $this->utc($this->requestedAt),
                'startsAtLabel'    => $this->utc($this->startsAt),
                'settingsUrl'      => $this->settingsUrl,
            ],
        );
    }

    private function utc(string $time): string
    {
        return CarbonImmutable::parse($time)->utc()->translatedFormat('j F Y, H:i') . ' UTC';
    }
}
