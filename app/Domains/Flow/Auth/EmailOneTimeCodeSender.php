<?php

declare(strict_types=1);

namespace App\Domains\Flow\Auth;

use Fapost\Foundation\Auth\Contracts\OneTimeCodeSenderInterface;
use Fapost\Foundation\Auth\DTO\OneTimeCodeDelivery;
use Fapost\Foundation\Auth\DTO\OneTimeCodeMessage;
use Fapost\Foundation\Auth\Enums\OneTimeCodeChannel;
use Illuminate\Contracts\Mail\Factory;
use Throwable;

/**
 * Core's default sender of email codes: the platform's mailer (`MAIL_*`), a fixed localized message signed with the
 * assistant's name. An operator package replaces it by registering its own email sender.
 *
 * The mail goes out synchronously, inside the encrypted job that carries the code. A mailer failure is reported as
 * a failed delivery with the exception's class only: a transport's message may quote the recipient.
 */
final readonly class EmailOneTimeCodeSender implements OneTimeCodeSenderInterface
{
    /** Mailer transports that deliver nothing (and `log` writes the whole mail, code included, to the log). */
    public const array UNDELIVERING_TRANSPORTS = ['log', 'array'];

    public function __construct(private Factory $mail)
    {
    }

    public function channel(): OneTimeCodeChannel
    {
        return OneTimeCodeChannel::Email;
    }

    public function send(OneTimeCodeMessage $message): OneTimeCodeDelivery
    {
        $mail = new OneTimeCodeMail($message->code(), $message->brandName, $message->expiresInMinutes)
            ->locale($message->locale);

        try {
            $this->mail->mailer()->to($message->destination)->send($mail);
        } catch (Throwable $exception) {
            return OneTimeCodeDelivery::failed('mailer_error: ' . $exception::class);
        }

        return OneTimeCodeDelivery::sent();
    }
}
