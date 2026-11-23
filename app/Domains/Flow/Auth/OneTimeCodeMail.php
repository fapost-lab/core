<?php

declare(strict_types=1);

namespace App\Domains\Flow\Auth;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use LogicException;
use SensitiveParameter;

/**
 * The email that carries a contact's one-time code. Its text is fixed and localized (`lang/<locale>/auth_code.php`);
 * the flow author writes none of it, so the platform's address never sends an author's text to an address a flow
 * chose. Sent at once by {@see EmailOneTimeCodeSender} inside the code's own encrypted job, never queued again: a
 * queued mailable would put the code into a second, unencrypted queue payload.
 */
final class OneTimeCodeMail extends Mailable
{
    public function __construct(
        #[SensitiveParameter]
        private readonly string $code,
        private readonly string $brandName,
        private readonly int $expiresInMinutes,
    ) {
    }

    /**
     * @throws LogicException always: the mail holds a code and is never queued
     */
    public function __serialize(): array
    {
        throw new LogicException('A one-time code mail holds a code and cannot be serialized.');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('auth_code.subject', ['brand' => $this->brandName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.auth.code',
            with: [
                'code'    => $this->code,
                'brand'   => $this->brandName,
                'minutes' => $this->expiresInMinutes,
            ],
        );
    }
}
