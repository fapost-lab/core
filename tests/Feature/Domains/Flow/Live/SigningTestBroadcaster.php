<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Live;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A Pusher-style broadcaster without Pusher: it authorizes through the application's channels and "signs" with the
 * channel name, so a test can tell an allowed subscription from a refused one.
 */
final class SigningTestBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public function auth($request): mixed
    {
        $channelName = $this->normalizeChannelName((string) $request->channel_name);

        if ('' === $channelName || ($this->isGuardedChannel((string) $request->channel_name) && ! $this->retrieveUser($request, $channelName))) {
            throw new AccessDeniedHttpException();
        }

        return $this->verifyUserCanAccessChannel($request, $channelName);
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return ['auth' => 'signed:' . $request->channel_name];
    }

    public function broadcast(array $channels, $event, array $payload = []): void
    {
    }
}
