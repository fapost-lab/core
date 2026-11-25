<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Enums\NotifyMode;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\NotifyNodeHandler;
use App\Domains\Flow\Handlers\SendMessageNodeHandler;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Nodes\AnnotationNodeTypes;
use App\Domains\Flow\Support\AddresseeResolver;
use App\Domains\Flow\Support\FlowTriggerComparison;
use App\Domains\Flow\Templates\TemplateExpander;

/**
 * What publishing a draft would change that needs a right beyond publishing: a saved credential the published version
 * does not reference yet, an automatic trigger (any type but `message`) made live, changed or removed, and a message to
 * tags or a segment (a broadcast) added or changed. And what publishing may not change at all: a `notify` node in the
 * retired `contacts` mode added or changed.
 *
 * Both compare the draft with what runs now: credentials with the flow's published definition (a template's too),
 * triggers with the flow's live rows ({@see FlowTriggerSet::live()}). Dropping a credential reference needs nothing;
 * any change to an automatic trigger does. The draft is compared before template expansion: a credential a placed
 * template brings is the template's, checked when the template is published.
 *
 * @phpstan-import-type Trigger from FlowTriggerComparison
 */
final readonly class FlowPublishChanges
{
    public function __construct(
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowTriggerSet $triggerSet,
        private TemplateExpander $templates,
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
     * Whether publishing the draft adds a `send_message` node to tags or a segment, or changes what one sends or to whom
     * (audience, text, broadcast name), against the published version. Removing one needs nothing.
     *
     * Unlike the credential check, the draft is compared after template expansion, as it will be published: a placed
     * template can bring such a node, and a host's parameter values can rewrite its text. The copies' ids are stable
     * (UUIDv5 of the reference and the template node), and the published definition is stored expanded, so the two
     * compare by node id. A draft whose expansion fails is compared as it is: its publish is refused anyway.
     *
     * The comparison covers the node's own fields only. What its `{{flow.*}}` placeholders read (set by an `assign` or
     * an `input` node upstream) and the edges, loops and `call` nodes leading to it are not compared, so a publisher
     * without `ManageBroadcast` can still change what an approved broadcast says and how often it goes out that way.
     * Whether the right should reach that far is an open authorization decision, not an oversight here.
     */
    public function changesAudienceMessages(FlowDraft $draft): bool
    {
        $drafted = self::audienceMessages($this->expandedNodes($draft));

        if ([] === $drafted) {
            return false;
        }

        $published = $this->definitions->findLatestPublishedByFlowId($draft->flow_id);
        $current   = null === $published ? [] : self::audienceMessages(is_array($published->nodes) ? $published->nodes : []);

        foreach ($drafted as $id => $message) {
            if (! array_key_exists($id, $current) || $current[$id] !== $message) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ids of the `notify` nodes in the retired `contacts` mode that publishing the draft would add, or change against
     * the published version (mode, assistant, audience, tags, message). An unchanged one may stay; removing one needs
     * nothing. Such a mode can no longer be authored: the same broadcast is a `send_message` to tags or a segment.
     *
     * Compared like {@see changesAudienceMessages()}: a flow's draft after template expansion, by node id, against the
     * published definition, so a template copy and a host's parameter values count; a template's draft as it is,
     * against the template's published version.
     *
     * @return list<string>
     */
    public function changedContactNotifications(FlowDraft $draft): array
    {
        $drafted = self::contactNotifications($this->expandedNodes($draft));

        if ([] === $drafted) {
            return [];
        }

        $published = $this->definitions->findLatestPublishedByFlowId($draft->flow_id);
        $current   = null === $published ? [] : self::contactNotifications(is_array($published->nodes) ? $published->nodes : []);
        $changed   = [];

        foreach ($drafted as $id => $notification) {
            if (! array_key_exists($id, $current) || $current[$id] !== $notification) {
                $changed[] = (string) $id;
            }
        }

        return $changed;
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

        $diff = FlowTriggerComparison::diff(
            self::automatic($this->triggerSet->live($draft->flow_id)),
            self::automatic(FlowTriggerSet::normalize($draft->triggers)),
        );

        return [] !== $diff['added'] || [] !== $diff['changed'] || [] !== $diff['removed'];
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
     * @return list<Trigger> the triggers other than `message`
     */
    private static function automatic(array $triggers): array
    {
        $automatic = [];

        foreach ($triggers as $trigger) {
            if (FlowTriggerType::Message->value !== $trigger['type']) {
                $automatic[] = $trigger;
            }
        }

        return $automatic;
    }

    /**
     * The `send_message` nodes to tags or a segment, by id, as what decides who gets what: the audience, the text and
     * the broadcast name, with keys sorted (jsonb does not keep their order) and tags in a stable order.
     *
     * @param  array<array-key, mixed>  $nodes
     *
     * @return array<string, string>
     */
    private static function audienceMessages(array $nodes): array
    {
        $messages = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || SendMessageNodeHandler::TYPE !== ($node['type'] ?? null) || ! is_array($node['config'] ?? null)) {
                continue;
            }

            $config    = $node['config'];
            $addressee = AddresseeResolver::parse($config);
            $id        = $node['id'] ?? null;

            if (! AddresseeResolver::isAudience($addressee['kind']) || ! is_scalar($id)) {
                continue;
            }

            $tags = $addressee['tags'];
            sort($tags);

            $text = $config['text'] ?? null;

            if (is_array($text)) {
                ksort($text);
            }

            $messages[(string) $id] = json_encode([
                'kind'           => $addressee['kind'],
                'tags'           => AddresseeResolver::KIND_TAGS === $addressee['kind'] ? $tags : [],
                'segment_id'     => AddresseeResolver::KIND_SEGMENT === $addressee['kind'] ? mb_strtolower($addressee['segment_id']) : '',
                'text'           => $text,
                'broadcast_name' => $config['broadcast_name'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $messages;
    }

    /**
     * The `notify` nodes in `contacts` mode, by id, as what decides who gets what: the assistant, the audience, the tags
     * in a stable order and the message with its languages sorted (jsonb does not keep their order).
     *
     * @param  array<array-key, mixed>  $nodes
     *
     * @return array<string, string>
     */
    private static function contactNotifications(array $nodes): array
    {
        $notifications = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || NotifyNodeHandler::TYPE !== ($node['type'] ?? null) || ! is_array($node['config'] ?? null)) {
                continue;
            }

            $config = $node['config'];
            $id     = $node['id'] ?? null;

            if (NotifyMode::Contacts->value !== ($config['mode'] ?? null) || ! is_scalar($id)) {
                continue;
            }

            $tags = is_array($config['tags'] ?? null) ? array_values(array_filter($config['tags'], 'is_string')) : [];
            sort($tags);

            $message = $config['message'] ?? null;

            if (is_array($message)) {
                ksort($message);
            }

            $notifications[(string) $id] = json_encode([
                'mode'           => $config['mode'],
                'assistant_id'   => $config['assistant_id'] ?? null,
                'contact_target' => $config['contact_target'] ?? null,
                'tags'           => $tags,
                'message'        => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $notifications;
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

    /**
     * The draft's nodes with its template references replaced by their copies, as {@see PublishFlowService} publishes
     * them; a template's own draft is published unexpanded.
     *
     * @return array<int, mixed>
     */
    private function expandedNodes(FlowDraft $draft): array
    {
        [$nodes, $edges] = AnnotationNodeTypes::strip(
            is_array($draft->nodes) ? $draft->nodes : [],
            is_array($draft->edges) ? $draft->edges : [],
        );

        if ($draft->isTemplate()) {
            return $nodes;
        }

        $expansion = $this->templates->expand(
            $nodes,
            $edges,
            (string) $draft->assistant_id,
            (string) $draft->expression_engine,
        );

        return [] === $expansion->errors ? $expansion->nodes : $nodes;
    }
}
