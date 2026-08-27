<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Transports;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use FAPost\Foundation\Flow\Call\CallContext;
use FAPost\Foundation\Flow\Call\CallRequest;
use FAPost\Foundation\Flow\Call\CallResult;
use FAPost\Foundation\Flow\Call\CallTransportInterface;
use LogicException;
use Throwable;

/**
 * Built-in {@code handler} transport — dispatches the call to an in-process
 * {@see \FAPost\Foundation\Action\ActionHandlerInterface} resolved from the
 * {@see ActionHandlerRegistry} by id.
 *
 * Target shape: action id (e.g. "crm.sync_contact"). Parameters are passed
 * through verbatim to {@code handle($parameters, $context)}; return value is
 * placed into {@see CallResult::$payload}. Action exceptions become
 * {@code CallResult::error('action_exception', …)} — never bubble up.
 */
final readonly class HandlerTransport implements CallTransportInterface
{
    public const string ID = 'handler';

    public function __construct(
        private ActionHandlerRegistry $registry,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function execute(CallRequest $request, CallContext $context): CallResult
    {
        $actionId = mb_trim($request->target);

        if ('' === $actionId) {
            return CallResult::error('invalid_target', metadata: ['target' => $request->target]);
        }

        try {
            $handler = $this->registry->get($actionId);
        } catch (LogicException $exception) {
            return CallResult::error('action_not_found', metadata: ['action_id' => $actionId, 'error' => $exception->getMessage()]);
        }

        try {
            $payload = $handler->handle($request->parameters, $context);
        } catch (Throwable $exception) {
            return CallResult::error(
                'action_exception',
                metadata: [
                    'action_id' => $actionId,
                    'exception' => $exception::class,
                    'message'   => $exception->getMessage(),
                ],
            );
        }

        return CallResult::ok($payload, ['action_id' => $actionId]);
    }
}
