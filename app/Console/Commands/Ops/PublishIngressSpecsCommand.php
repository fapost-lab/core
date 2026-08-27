<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Domains\Webhook\Services\IngressSpecPublisher;
use Illuminate\Console\Command;
use JsonException;

/**
 * Publishes ingress specs so an external gateway can verify webhook signatures
 * without platform-specific code.
 *
 * Must run before any channel is pointed at the gateway, and again after any
 * deploy that adds or changes a channel adapter — a gateway with no spec for a
 * platform cannot verify it.
 */
final class PublishIngressSpecsCommand extends Command
{
    protected $signature = 'ops:ingress-specs-publish';

    protected $description = 'Publish declarative channel ingress specs to Redis for the external webhook gateway';

    public function __construct(
        private readonly IngressSpecPublisher $publisher,
    ) {
        parent::__construct();
    }

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $published = $this->publisher->publishAll();

        if ([] === $published) {
            $this->components->warn(
                'No adapter publishes an ingress spec. The gateway cannot verify any platform yet.'
            );

            return self::SUCCESS;
        }

        foreach ($published as $platform => $spec) {
            $this->components->twoColumnDetail(
                $platform,
                $spec->scheme->value . ('' === (string) $spec->parameter ? '' : " ({$spec->parameter})"),
            );
        }

        $this->components->info(sprintf('Published %d ingress spec(s).', count($published)));

        return self::SUCCESS;
    }
}
