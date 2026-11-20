<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Models\FlowDraft;

/**
 * What publishing a draft would change that needs a right beyond publishing: a saved credential the published version
 * does not reference yet, and an automatic trigger (any type but `message`) made live, changed or removed.
 *
 * Both compare the draft with what runs now: credentials with the flow's published definition (a template's too),
 * triggers with the flow's live rows ({@see FlowTriggerSet::live()}). Dropping a credential reference needs nothing;
 * any change to an automatic trigger does. The draft is compared before template expansion: a credential a placed
 * template brings is the template's, checked when the template is published.
 *
 * @phpstan-import-type Trigger from FlowTriggerSet
 */
final readonly class FlowPublishChanges
{
    public function __construct(
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowTriggerSet $triggerSet,
    ) {
    }

    /**
     * The ids of saved credentials the draft's `call` nodes reference and the published version does not, lowercased.
     *
     * @return list<string>
     */
    public function addedCredentialIds(FlowDraft $draft): array
    {
        $drafted = self::credentialIds(is_array($draft->nodes) ? $draft->nodes : []);

        if ([] === $drafted) {
            return [];
        }

        $published = $this->definitions->findLatestPublishedByFlowId($draft->flow_id);
        $current   = null === $published ? [] : self::credentialIds(is_array($published->nodes) ? $published->nodes : []);

        return array_values(array_diff($drafted, $current));
    }

    /**
     * Whether publishing the draft adds, removes or changes (type, config, active flag, priority) a trigger other than
     * `message`. A draft whose list was never written publishes the live rows as they are, so it changes nothing.
     */
    public function changesAutomations(FlowDraft $draft): bool
    {
        if (! is_array($draft->triggers)) {
            return false;
        }

        $drafted = self::automatic(FlowTriggerSet::normalize($draft->triggers));
        $live    = self::automatic($this->triggerSet->live($draft->flow_id));

        if (count($drafted) !== count($live) || [] !== array_diff_key($drafted, $live)) {
            return true;
        }

        foreach ($drafted as $id => $trigger) {
            $current = $live[$id];

            if ($trigger['type'] !== $current['type']
                || $trigger['is_active'] !== $current['is_active']
                || $trigger['priority'] !== $current['priority']
                || ! FlowTriggerSet::sameConfig($trigger['config'], $current['config'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the flow has a live trigger other than `message`, active or not: turning such a flow on starts runs
     * nobody asked for.
     */
    public function hasLiveAutomation(FlowDraft $draft): bool
    {
        return [] !== self::automatic($this->triggerSet->live($draft->flow_id));
    }

    /**
     * @param  list<Trigger>  $triggers
     *
     * @return array<string, Trigger> the triggers other than `message`, by id
     */
    private static function automatic(array $triggers): array
    {
        $automatic = [];

        foreach ($triggers as $trigger) {
            if (FlowTriggerType::Message->value !== $trigger['type']) {
                $automatic[$trigger['id']] = $trigger;
            }
        }

        return $automatic;
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     *
     * @return list<string>
     */
    private static function credentialIds(array $nodes): array
    {
        $ids = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || CallNodeHandler::TYPE !== ($node['type'] ?? null) || ! is_array($node['config'] ?? null)) {
                continue;
            }

            $id = $node['config']['credential_id'] ?? null;

            if (is_string($id) && '' !== $id) {
                $ids[mb_strtolower($id)] = true;
            }
        }

        return array_keys($ids);
    }
}
