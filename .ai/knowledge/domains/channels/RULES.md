---
id: rule-channels
type: rule
status: active
summary: Send only via MessageSender, new messenger means new integration, queues by purpose
domains:
  - channels
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Channels/**"
  - "app/Domains/Messaging/**"
  - "app/Jobs/Messaging/**"
  - config/messaging.php
  - "tests/Unit/Domains/Channels/**"
  - "tests/Unit/Domains/Messaging/**"
  - "tests/Feature/Channels/**"
  - tests/Architecture/MessagingBoundariesTest.php
reviewed_at: 2026-09-21
---
# Channels rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **`MessageSender` reserves the idempotency key and applies the per-chat rate limit before
  calling the provider.** Why: provider limits are handled preventively, not after a provider
  error (AGENTS.md).
- **On channel save, the Redis routing write happens before the provider webhook sync, and both
  run after commit** (`ChannelObserver`). Why: the provider must never deliver to a hash the
  registry does not know.
- **The webhook public hash is rotated only through `ChannelService`.**
- **Rotating the webhook hash needs `Permission::RotateChannelToken` and access to the channel's
  assistant; `ManageAssistants` is not enough** (`ChannelPolicy::rotateWebhook`, admins through
  `Gate::before`; `ChannelRotationAuthorizationTest`). Why: rotation drops the old hash from routing
  at once, so deliveries fail until the provider is switched to the new URL; it is a separately
  granted, sensitive permission — `content_manager` does not have it.

## Rules

- **Send through `MessageSenderInterface`, never through `ProviderSenderInterface` or a provider
  namespace.** Why: idempotency, rate limit and transcript live in `MessageSender`. Enforced for
  Flow only, by `tests/Architecture/MessagingBoundariesTest.php`: a provider module is any
  sub-namespace of `App\Domains\Channels` except the shared layers (`Contracts`, `Enums`,
  `Models`, `Observers`, `Policies`, `Providers`, `Services`), so a new provider is covered
  automatically and a new shared layer must be added to that exclusion list.
- **A new messenger is a new integration, not a branch.** Add a `ChannelIntegrationDefinition`
  tagged `channels.integration` with its adapter, sender and registrar, in its own provider —
  `Telegram/TelegramChannelServiceProvider.php` is the model. Review only. *(proposed)*
- **Pick the queue by purpose:** replies in an active dialogue `messaging.transactional`,
  broadcasts `messaging.broadcast`, service and lifecycle work `messaging.system`. Source:
  AGENTS.md.
