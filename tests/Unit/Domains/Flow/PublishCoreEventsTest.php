<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Broadcasting\Events\BroadcastCompleted;
use App\Domains\Contact\Events\ContactCreated;
use App\Domains\Contact\Events\ContactTagAdded;
use App\Domains\Contact\Events\ContactTagRemoved;
use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Conversation\Events\OperatorRequestStateChanged;
use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Domains\Flow\Events\CoreEventCatalog;
use App\Domains\Flow\Listeners\PublishCoreEvents;
use App\Domains\Flow\Services\EventChainContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class PublishCoreEventsTest extends TestCase
{
    public function test_contact_created_publishes_the_catalog_fields(): void
    {
        $publisher = new RecordingCorePublisher();

        $this->listener($publisher)->contactCreated(new ContactCreated('t-1', 'c-1', 'telegram', 'ru'));

        $this->assertPublished($publisher, CoreEventCatalog::CONTACT_CREATED, ['contact_id' => 'c-1', 'platform' => 'telegram', 'language' => 'ru']);
    }

    public function test_contact_tag_added_publishes_the_catalog_fields(): void
    {
        $publisher = new RecordingCorePublisher();

        $this->listener($publisher)->contactTagAdded(new ContactTagAdded('t-1', 'c-1', 'vip', 'staff-1'));

        $this->assertPublished($publisher, CoreEventCatalog::CONTACT_TAG_ADDED, ['contact_id' => 'c-1', 'tag' => 'vip', 'tagged_by' => 'staff-1']);
    }

    public function test_contact_tag_removed_publishes_the_catalog_fields(): void
    {
        $publisher = new RecordingCorePublisher();

        $this->listener($publisher)->contactTagRemoved(new ContactTagRemoved('t-1', 'c-1', 'vip'));

        $this->assertPublished($publisher, CoreEventCatalog::CONTACT_TAG_REMOVED, ['contact_id' => 'c-1', 'tag' => 'vip']);
    }

    public function test_broadcast_completed_publishes_the_catalog_fields(): void
    {
        $publisher = new RecordingCorePublisher();

        $this->listener($publisher)->broadcastCompleted(new BroadcastCompleted('t-1', 'b-1', 'Promo', 10, 8, 2));

        $this->assertPublished($publisher, CoreEventCatalog::BROADCAST_COMPLETED, [
            'broadcast_id'     => 'b-1',
            'name'             => 'Promo',
            'recipients_total' => 10,
            'sent'             => 8,
            'failed'           => 2,
        ]);
    }

    /**
     * The publisher counts one more than the chain context, so a broadcast a flow started at depth 4 (chain depth 5)
     * publishes at 5 and one started at depth 5 (chain depth 6) past the limit; a console broadcast (0) publishes at 1.
     */
    public function test_broadcast_completed_is_published_one_below_the_broadcasts_own_chain_depth(): void
    {
        $chain     = new EventChainContext();
        $publisher = new RecordingCorePublisher(chain: $chain);
        $listener  = new PublishCoreEvents($publisher, new RecordingExceptionHandler(), $chain);

        $listener->broadcastCompleted(new BroadcastCompleted('t-1', 'b-1', 'Promo', 1, 1, 0));
        $listener->broadcastCompleted(new BroadcastCompleted('t-1', 'b-2', 'Promo', 1, 1, 0, 5));
        $listener->broadcastCompleted(new BroadcastCompleted('t-1', 'b-3', 'Promo', 1, 1, 0, 6));

        $this->assertSame([0, 4, 5], array_column($publisher->calls, 'depth'));
        $this->assertSame(0, $chain->current(), 'the listener leaves the chain context as it found it');
    }

    public function test_a_timed_out_operator_request_is_published(): void
    {
        $publisher = new RecordingCorePublisher();

        $this->listener($publisher)->operatorRequestStateChanged(
            new OperatorRequestStateChanged('t-1', 'r-1', OperatorRequestState::Timeout, null, 'a-1', 'c-1'),
        );

        $this->assertPublished($publisher, CoreEventCatalog::OPERATOR_REQUEST_TIMED_OUT, ['request_id' => 'r-1', 'contact_id' => 'c-1', 'assistant_id' => 'a-1']);
    }

    public function test_other_operator_request_states_publish_nothing(): void
    {
        $publisher = new RecordingCorePublisher();
        $listener  = $this->listener($publisher);

        foreach (OperatorRequestState::cases() as $state) {
            if (OperatorRequestState::Timeout !== $state) {
                $listener->operatorRequestStateChanged(new OperatorRequestStateChanged('t-1', 'r-1', $state, null, 'a-1', 'c-1'));
            }
        }

        $this->assertSame([], $publisher->calls);
    }

    public function test_a_failing_publisher_is_reported_and_never_rethrown(): void
    {
        $failure   = new RuntimeException('queue down');
        $publisher = new RecordingCorePublisher($failure);
        $reporter  = new RecordingExceptionHandler();

        (new PublishCoreEvents($publisher, $reporter, new EventChainContext()))->contactCreated(new ContactCreated('t-1', 'c-1', 'telegram', 'en'));

        $this->assertSame([$failure], $reporter->reported);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPublished(RecordingCorePublisher $publisher, string $name, array $payload): void
    {
        $this->assertCount(1, $publisher->calls);
        $this->assertSame('t-1', $publisher->calls[0]['tenant']);
        $this->assertSame($name, $publisher->calls[0]['name']);
        $this->assertSame($payload, $publisher->calls[0]['payload']);
        $this->assertEqualsCanonicalizing(CoreEventCatalog::fields($name), array_keys($publisher->calls[0]['payload']));
        $this->assertSame(['origin' => 'core'], $publisher->calls[0]['source']);
    }

    private function listener(RecordingCorePublisher $publisher): PublishCoreEvents
    {
        return new PublishCoreEvents($publisher, new RecordingExceptionHandler(), new EventChainContext());
    }
}

final class RecordingCorePublisher implements FlowTriggerEventPublisherInterface
{
    /** @var list<array{tenant: string, name: string, payload: array<string, mixed>, source: array<string, mixed>, depth: int|null}> */
    public array $calls = [];

    public function __construct(
        private readonly ?Throwable $failure = null,
        private readonly ?EventChainContext $chain = null,
    ) {
    }

    public function publish(string $tenantId, string $eventName, array $payload, array $source): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->calls[] = ['tenant' => $tenantId, 'name' => $eventName, 'payload' => $payload, 'source' => $source, 'depth' => $this->chain?->current()];
    }
}

final class RecordingExceptionHandler implements ExceptionHandler
{
    /** @var list<Throwable> */
    public array $reported = [];

    public function report(Throwable $e): void
    {
        $this->reported[] = $e;
    }

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render($request, Throwable $e): void
    {
        throw $e;
    }

    public function renderForConsole($output, Throwable $e): void
    {
    }
}
