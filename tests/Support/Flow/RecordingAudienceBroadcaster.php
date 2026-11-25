<?php

declare(strict_types=1);

namespace Tests\Support\Flow;

use App\Domains\Flow\Contracts\AudienceBroadcasterInterface;
use App\Domains\Flow\DTOs\AudienceBroadcastRequest;
use App\Domains\Flow\DTOs\AudienceBroadcastResult;
use App\Domains\Flow\Enums\AudienceBroadcastOutcome;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * {@see AudienceBroadcasterInterface} that records every request and answers what the test sets: a started broadcast by
 * default, another outcome, or an exception.
 */
final class RecordingAudienceBroadcaster implements AudienceBroadcasterInterface
{
    /** @var list<AudienceBroadcastRequest> */
    public array $requests = [];

    public AudienceBroadcastResult $answer;

    public ?Throwable $failure = null;

    public function __construct()
    {
        $this->answer = new AudienceBroadcastResult(AudienceBroadcastOutcome::Started, 'broadcast-1', 7);
    }

    public function start(AudienceBroadcastRequest $request): AudienceBroadcastResult
    {
        $this->requests[] = $request;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->answer;
    }

    public function segmentExists(string $segmentId): bool
    {
        return true;
    }

    /**
     * The one request made.
     */
    public function sole(): AudienceBroadcastRequest
    {
        Assert::assertCount(1, $this->requests);

        return $this->requests[0];
    }
}
