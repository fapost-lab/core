<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Enums\NotifyMode;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\NotifyNodeHandler;
use App\Domains\Flow\Handlers\StartFlowNodeHandler;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Nodes\AnnotationNodeTypes;
use App\Domains\Flow\Subflow\SubflowPin;
use App\Domains\Flow\Support\AddresseeResolver;
use App\Domains\Flow\Support\AudienceMessageCone;
use App\Domains\Flow\Support\FlowTriggerComparison;
use App\Domains\Flow\Templates\TemplateExpander;
use App\Domains\Flow\Templates\TemplateUsageRepository;

/**
 * What publishing a draft would change that needs a right beyond publishing: a saved credential the published version
 * does not reference yet, an automatic trigger (any type but `message`) made live, changed or removed, and a message to
 * tags or a segment (a broadcast) added or changed, or what leads to one. And what publishing may not change at all: a `notify` node in the
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
        private TemplateUsageRepository $templateUsages,
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
     * The comparison covers the node's own fields; what leads to it is {@see changesWhatLeadsToAudienceMessages()}.
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
     * Whether publishing the draft changes what leads to a `send_message` node to tags or a segment, and with it what
     * the broadcast says (every `{{flow.*}}` it reads is written upstream) or how often it goes out
     * (`adr-20261125-broadcast-publish-right-covers-what-leads-to-it`). For a flow's draft, compared as
     * {@see changesAudienceMessages()} compares (after template expansion, by node id, against the published version):
     *
     * - a node's cone ({@see AudienceMessageCone}) — the node, every node that can reach it by an edge or a `loop_end`'s
     *   jump back to its `loop`, and the edges into them — differs from the cone of the same id, or the id is new;
     * - or the flow has such a node and the draft changes its expression engine or any of its triggers, `message`
     *   included: the triggers start the graph that leads to the node and set how often it runs.
     *
     * A `subflow` or `start_flow` node that starts a flow whose active definition has such a node counts as one too:
     * its `input_mapping` seeds the variables that flow's broadcast reads, and how often it runs is how often that flow
     * starts. Its cone is compared the same way.
     *
     * A template's draft is compared unexpanded with the template's published version. When its graph or its engine
     * changed, it needs the right if a node of its own of that kind has a changed cone, or if the active definition of
     * any flow that places the template has one: publishing a template republishes those flows without their own check.
     *
     * Removing such a node, and every change outside the cones, needs nothing. What a `subflow` callee returns is not
     * compared: the callee is another flow, checked by its own publish; nor is data from outside (a trigger's payload,
     * an event another flow emits, an API's answer, a contact's input), which is not part of a version.
     */
    public function changesWhatLeadsToAudienceMessages(FlowDraft $draft): bool
    {
        [$nodes, $edges] = $this->expandedGraph($draft);
        $published       = $this->definitions->findLatestPublishedByFlowId($draft->flow_id);
        $drafted         = AudienceMessageCone::of($nodes, $edges, $this->leadingNodeIds($nodes));

        if ($draft->isTemplate()) {
            return $this->templateChangesWhatLeadsToAudienceMessages($draft, $nodes, $edges, $drafted, $published);
        }

        if ([] === $drafted) {
            return false;
        }

        if (null === $published) {
            return true;
        }

        if (self::changesCones($drafted, $published)) {
            return true;
        }

        if ((string) $draft->expression_engine !== (string) $published->expression_engine) {
            return true;
        }

        return $this->changesTriggers($draft);
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
            $id = is_array($node) ? ($node['id'] ?? null) : null;

            if (! is_array($node) || ! is_scalar($id) || ! AudienceMessageCone::isAudienceMessage($node)) {
                continue;
            }

            /** @var array<array-key, mixed> $config */
            $config    = $node['config'];
            $addressee = AddresseeResolver::parse($config);

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
     * Whether a drafted cone is new or differs from the published cone of the same node id.
     *
     * @param  array<string, string>  $drafted
     */
    private static function changesCones(array $drafted, FlowDefinition $published): bool
    {
        if ([] === $drafted) {
            return false;
        }

        $current = AudienceMessageCone::of(
            is_array($published->nodes) ? $published->nodes : [],
            is_array($published->edges) ? $published->edges : [],
            array_keys($drafted),
        );

        foreach ($drafted as $id => $cone) {
            if (($current[$id] ?? null) !== $cone) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ids of the nodes whose cone the right covers: every `send_message` to tags or a segment, and every `subflow` or
     * `start_flow` node that starts a flow whose definition it runs has one — the pinned version of a pinned `subflow`,
     * the active definition otherwise.
     *
     * @param  array<array-key, mixed>  $nodes
     *
     * @return list<string>
     */
    private function leadingNodeIds(array $nodes): array
    {
        $ids        = AudienceMessageCone::audienceMessageIds($nodes);
        $broadcasts = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ! in_array($node['type'] ?? null, [SubflowNodeHandler::TYPE, StartFlowNodeHandler::TYPE], true)) {
                continue;
            }

            $id     = $node['id'] ?? null;
            $flowId = is_array($node['config'] ?? null) && is_string($node['config']['flow_id'] ?? null) ? mb_trim($node['config']['flow_id']) : '';

            if (! is_scalar($id) || '' === $flowId) {
                continue;
            }

            // A subflow pinned to a version runs that version, active or not; anything else runs the active one.
            $pin = SubflowNodeHandler::TYPE === $node['type'] && is_array($node['config']) ? SubflowPin::read($node['config']) : null;
            $key = $flowId . '@' . (is_int($pin) ? $pin : 'active');

            if (! array_key_exists($key, $broadcasts)) {
                $callee = is_int($pin)
                    ? $this->definitions->findRunnableByFlowIdAndVersion($flowId, $pin)
                    : $this->definitions->findLatestPublishedByFlowId($flowId);

                $broadcasts[$key] = null !== $callee
                    && [] !== AudienceMessageCone::audienceMessageIds(is_array($callee->nodes) ? $callee->nodes : []);
            }

            if ($broadcasts[$key]) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<array-key, mixed>   $nodes
     * @param  array<array-key, mixed>   $edges
     * @param  array<string, string>     $drafted  the cones of the template's own audience messages
     */
    private function templateChangesWhatLeadsToAudienceMessages(
        FlowDraft $draft,
        array $nodes,
        array $edges,
        array $drafted,
        ?FlowDefinition $published,
    ): bool {
        if (null === $published) {
            return [] !== $drafted;
        }

        $publishedNodes = is_array($published->nodes) ? $published->nodes : [];
        $publishedEdges = is_array($published->edges) ? $published->edges : [];

        $changed = AudienceMessageCone::ofGraph($nodes, $edges) !== AudienceMessageCone::ofGraph($publishedNodes, $publishedEdges)
            || (string) $draft->expression_engine !== (string) $published->expression_engine;

        if (! $changed) {
            return false;
        }

        if (self::changesCones($drafted, $published)) {
            return true;
        }

        foreach ($this->templateUsages->hostsOf($draft->flow_id) as $hostFlowId) {
            $host = $this->definitions->findLatestPublishedByFlowId($hostFlowId);

            if (null !== $host && [] !== $this->leadingNodeIds(is_array($host->nodes) ? $host->nodes : [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether publishing the draft adds, removes or changes any of the flow's triggers, `message` included. A draft
     * whose list was never written publishes the live rows as they are.
     */
    private function changesTriggers(FlowDraft $draft): bool
    {
        if (! is_array($draft->triggers)) {
            return false;
        }

        $diff = FlowTriggerComparison::diff(
            $this->triggerSet->live($draft->flow_id),
            FlowTriggerSet::normalize($draft->triggers),
        );

        return [] !== $diff['added'] || [] !== $diff['changed'] || [] !== $diff['removed'];
    }

    /**
     * The draft's nodes with its template references replaced by their copies, as {@see PublishFlowService} publishes
     * them; a template's own draft is published unexpanded.
     *
     * @return array<int, mixed>
     */
    private function expandedNodes(FlowDraft $draft): array
    {
        return $this->expandedGraph($draft)[0];
    }

    /**
     * The draft's nodes and edges without annotations and with its template references replaced by their copies, as
     * {@see PublishFlowService} publishes them; a template's own draft is published unexpanded. A draft whose expansion
     * fails is returned as it is: its publish is refused anyway.
     *
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}
     */
    private function expandedGraph(FlowDraft $draft): array
    {
        [$nodes, $edges] = AnnotationNodeTypes::strip(
            is_array($draft->nodes) ? $draft->nodes : [],
            is_array($draft->edges) ? $draft->edges : [],
        );

        if ($draft->isTemplate()) {
            return [$nodes, $edges];
        }

        $expansion = $this->templates->expand(
            $nodes,
            $edges,
            (string) $draft->assistant_id,
            (string) $draft->expression_engine,
        );

        return [] === $expansion->errors ? [$expansion->nodes, $expansion->edges] : [$nodes, $edges];
    }
}
