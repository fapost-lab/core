---
id: domain-contact
type: domain
status: active
summary: Contact identity, channel links, tags, groups and segments
domains:
  - contact
topics: []
load: domain
paths:
  - "app/Domains/Contact/**"
  - "database/migrations/tenant/*contact*"
  - "tests/*/Domains/Contact/**"
  - "app/Http/Controllers/Console/Contact*"
  - "app/Http/Requests/Console/*Contact*"
  - "resources/js/pages/Console/Contacts/**"
  - "resources/js/pages/Console/ContactSegments/**"
reviewed_at: 2026-11-25
---
# Contact

## Responsibility

Contact owns the identity of the people assistants talk to — one contact per tenant, platform
and external id, the platform being a plain string (a channel type, or `email`; `Services/ContactPlatforms` lists
what the filters and segment rules offer) — and the link between a contact and a channel (`ChannelContact`, with
`last_interaction_at`). It groups contacts three ways: tags (free-form strings), groups
(explicit member lists) and segments (saved rules evaluated on demand). A segment rule on a platform no
installed integration serves any more is kept and still evaluated, but the segment cannot be saved again until that
condition is removed (`ContactSegmentRequest` validates against `ContactPlatforms`, failing closed). It sends nothing
itself: the `notify` node's retired `contacts` mode sends through Broadcasting, and its old fan-out job was removed
after 0.25 (`adr-20261125-notify-contacts-retired-through-broadcasting`).

## Boundaries

- Bindings are plain `bind` (`ContactServiceInterface`, `ContactTagRepositoryInterface`) in
  `Providers/ContactServiceProvider.php`.
- Domain events: `ContactService::findOrCreate()` raises `Events/ContactCreated` for a contact it really created, and
  `ContactTagRepository` raises `ContactTagAdded` / `ContactTagRemoved` for every tag that really changed (tenant id
  read from the contact row); a no-op raises nothing. The event classes reference nothing of Flow (Contact imports Flow
  elsewhere, in `ContactCard`): Flow listens and publishes them as Core events
  (`adr-20261119-core-events-start-contactless-runs`).
- Consumers:
  - Flow imports Contact the most, mostly the concrete `Contact` model (including in Flow's own
    contracts); `ContactWriter` saves it.
  - Webhook uses `ContactServiceInterface` and `PlatformEnum`.
  - Broadcasting uses the tag repository contract, plus the concrete `ContactSegmentResolver`,
    `ContactSegment` and `ChannelContact`.
  - Conversation uses the `Contact` and `ChannelContact` models.
- Depends on Flow (`VariableSchemaRegistryInterface`, `VariableType` in `ContactCard`) — an import cycle with
  Flow — and on Channels (the `Channel` model, `ChannelTypeCast`, `ChannelRegistryInterface` in `ContactPlatforms`),
  Staff policies' `Permission`/`User`, the `Assistant` model, Audit's trail contracts in the console services, and
  Tenancy (`TenantContextInterface`, and `PeriodQuota`/`RefusedWork` in `InboundContactGate`).

## Entry points

- `Services/ContactService.php`, `Repositories/ContactTagRepository.php`,
  `Services/ContactSegmentResolver.php`.
- Policies `ContactPolicy`, `ContactGroupPolicy`.
- Inertia console: `App\Http\Controllers\Console\ContactController` with `Services/AssistantContactService.php`
  (the assistant's contacts and the two writes, tags and groups) and `Services/ContactCard.php` (the card's
  sections); pages in `resources/js/pages/Console/Contacts/`.
- Inertia console, segments: `ContactSegmentController`, `Services/ContactSegmentService.php` (tenant-bounded
  reads and writes, stores rules in the shape the removed Filament form wrote), `ContactSegmentRequest`; pages in
  `resources/js/pages/Console/ContactSegments/`. `SegmentConditionType` (with `SegmentOperator`,
  `SegmentValueArity`) is where the rule builder's choices come from.
- Inertia console, contact groups: `ContactGroupController`; pages in
  `resources/js/pages/Console/ContactGroups/`. Tag options for the builder:
  `app/Http/Controllers/Builder/ContactTagsController.php`.
