<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Exceptions\TelegramApiException;

final class TelegramDeliveryResolver
{
    /**
     * @var list<TelegramDeliveryActionInterface>
     */
    private array $actions = [];

    /**
     * @param  iterable<TelegramDeliveryActionInterface>  $actions
     */
    public function __construct(iterable $actions)
    {
        foreach ($actions as $action) {
            $this->actions[] = $action;
        }
    }

    public function resolve(string $payloadType): TelegramDeliveryActionInterface
    {
        foreach ($this->actions as $action) {
            if ($action->supports($payloadType)) {
                return $action;
            }
        }

        throw new TelegramApiException("Unsupported Telegram payload type [{$payloadType}].");
    }
}
