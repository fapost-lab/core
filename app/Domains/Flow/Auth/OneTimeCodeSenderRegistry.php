<?php

declare(strict_types=1);

namespace App\Domains\Flow\Auth;

use Closure;
use Fapost\Foundation\Auth\Contracts\OneTimeCodeSenderInterface;
use Fapost\Foundation\Auth\Contracts\OneTimeCodeSenderRegistryInterface;
use Fapost\Foundation\Auth\Enums\OneTimeCodeChannel;
use LogicException;

/**
 * Core's registry of one-time code senders: one per channel.
 *
 * Core's own senders are defaults ({@see setDefault()}), built on demand; a package's registered sender takes the channel over
 * whatever order the providers booted in, so an operator package can send email codes its own way. Two packages
 * claiming one channel is a boot error. The registry holds stateless sender objects and no tenant data; it is
 * closed once the application has booted.
 */
final class OneTimeCodeSenderRegistry implements OneTimeCodeSenderRegistryInterface
{
    /** @var array<string, OneTimeCodeSenderInterface> */
    private array $registered = [];

    /** @var array<string, Closure(): ?OneTimeCodeSenderInterface> */
    private array $defaults = [];

    private bool $frozen = false;

    public function register(OneTimeCodeSenderInterface $sender): void
    {
        $this->assertOpen();

        $channel = $sender->channel()->value;

        if (isset($this->registered[$channel])) {
            throw new LogicException(sprintf(
                'A one-time code sender for "%s" is already registered (%s); %s cannot take it over.',
                $channel,
                $this->registered[$channel]::class,
                $sender::class,
            ));
        }

        $this->registered[$channel] = $sender;
    }

    /**
     * Core's own sender for a channel, used while no package registers one. It is built on every {@see find()}, so
     * it takes the mailer of the current job rather than the one bound when the registry was made.
     *
     * @param  Closure(): ?OneTimeCodeSenderInterface  $sender  null when the channel cannot deliver now (a log-only mailer)
     */
    public function setDefault(OneTimeCodeChannel $channel, Closure $sender): void
    {
        $this->assertOpen();

        $this->defaults[$channel->value] = $sender;
    }

    public function find(OneTimeCodeChannel $channel): ?OneTimeCodeSenderInterface
    {
        if (isset($this->registered[$channel->value])) {
            return $this->registered[$channel->value];
        }

        $default = $this->defaults[$channel->value] ?? null;

        return null !== $default ? $default() : null;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    private function assertOpen(): void
    {
        if ($this->frozen) {
            throw new LogicException('Cannot register one-time code senders after boot.');
        }
    }
}
