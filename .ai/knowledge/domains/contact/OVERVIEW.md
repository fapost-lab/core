---
id: domain-contact
type: domain
status: active
summary: Contact identity, channel links, tags, groups, segments and the notify fan-out job
domains:
  - contact
topics: []
load: domain
paths:
  - "app/Domains/Contact/**"
  - "app/Filament/Assistant/Resources/Contact*/**"
  - "database/migrations/tenant/*contact*"
  - "tests/*/Domains/Contact/**"
  - "app/Http/Controllers/Console/Contact*"
  - "app/Http/Requests/Console/*Contact*"
  - "resources/js/pages/Console/Contacts/**"
  - "resources/js/pages/Console/ContactSegments/**"
reviewed_at: 2026-10-09
---
# Contact

## Responsibility

Contact owns the identity of the people assistants talk to — one contact per tenant, platform
and external id — and the link between a contact and a channel (`ChannelContact`, with
`last_interaction_at`). It groups contacts three ways: tags (free-form strings), groups
(explicit member lists) and segments (saved rules evaluated on demand). It also holds
`SendContactNotificationJob`, which fans Flow's `notify` node out to contacts.

## Boundaries

- Bindings are plain `bind` (`ContactServiceInterface`, `ContactTagRepositoryInterface`) in
  `Providers/ContactServiceProvider.php`.
- Consumers:
  - Flow imports Contact the most, mostly the concrete `Contact` model (including in Flow's own
    contracts); `ContactWriter` saves it.
  - Webhook uses `ContactServiceInterface` and `PlatformEnum`.
  - Broadcasting uses the tag repository contract, plus the concrete `ContactSegmentResolver`,
    `ContactSegment` and `ChannelContact`.
  - Conversation uses the `Contact` and `ChannelContact` models.
- Depends on Flow (`ContentTranslatorInterface`, `ContactNotifyTarget`) — an import cycle with
  Flow — and on the `Channel` model, Staff policies' `Permission`/`User`, Conversation's
  `MessageOrigin`, and `App\Jobs\Messaging\BroadcastSendJob`.

## Entry points

- `Services/ContactService.php`, `Repositories/ContactTagRepository.php`,
  `Services/ContactSegmentResolver.php`.
- `Jobs/SendContactNotificationJob.php` (queue `messaging.broadcast`).
- Policies `ContactPolicy`, `ContactGroupPolicy`.
- Inertia console: `App\Http\Controllers\Console\ContactController` with `Services/AssistantContactService.php`
  (the assistant's contacts and the two writes, tags and groups) and `Services/ContactCard.php` (the card's
  sections); pages in `resources/js/pages/Console/Contacts/`.
- Inertia console, segments: `ContactSegmentController`, `Services/ContactSegmentService.php` (tenant-bounded
  reads and writes, stores rules in the shape Filament writes), `ContactSegmentRequest`; pages in
  `resources/js/pages/Console/ContactSegments/`. `SegmentConditionType` (with `SegmentOperator`,
  `SegmentValueArity`) is where the rule builder's choices come from, on both UIs.
- Admin UI: `app/Filament/Assistant/Resources/{Contacts,ContactGroups,ContactSegments}`; tag
  options for the builder: `app/Http/Controllers/Builder/ContactTagsController.php`.
