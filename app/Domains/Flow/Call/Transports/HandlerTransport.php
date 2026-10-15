<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Transports;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Contracts\ActionAvailabilityInterface;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Fapost\Foundation\Flow\Call\CallResult;
use Fapost\Foundation\Flow\Call\CallTransportInterface;
use Throwable;

/**
 * Built-in {@code handler} transport — dispatches the call to an in-process
 * {@see \Fapost\Foundation\Action\ActionHandlerInterface} resolved from the
 * {@see ActionHandlerRegistry} by id.
 *
 * Target shape: action id (e.g. "crm.sync_contact"). Parameters are passed
 * through verbatim to {@code handle($parameters, $context)}; return value is
 * placed into {@see CallResult::$payload}. Action exceptions become
 * {@code CallResult::error('action_exception', …)} — never bubble up.
 *
 * An action registered by a Solution that the current tenant has not activated is refused with
 * {@code solution_inactive} before its handler is built, let alone called. The check runs on every
 * call, against the scope the call runs in. A refusal still counts as one call execution: the node
 * spends the quota before it reaches the transport.
 */
final readonly class HandlerTransport implements CallTransportInterface
{
    public const string ID = 'handler';

    public function __construct(
        private ActionHandlerRegistry $registry,
        private ActionAvailabilityInterface $availability,
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

        if (!$this->registry->has($actionId)) {
            return CallResult::error('action_not_found', metadata: ['action_id' => $actionId, 'error' => "Action handler '{$actionId}' is not registered."]);
        }

        $solutionId = $this->registry->ownerOf($actionId);

        if (!$this->availability->isAvailable($solutionId)) {
            return CallResult::error('solution_inactive', metadata: ['action_id' => $actionId, 'solution_id' => $solutionId]);
        }

        try {
            $handler = $this->registry->get($actionId);
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
