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
reviewed_at: 2026-10-09
---
# Channels rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **`MessageSender` reserves the idempotency key and applies the per-chat rate limit before
  calling the provider.** Why: provider limits are handled preventively, not after a provider
  error (`conventions/queues.md`).
- **On channel save, the Redis routing write happens before the provider webhook sync, and both
  run after commit** (`ChannelObserver`). Why: the provider must never deliver to a hash the
  registry does not know. A rotation follows the same order: `ChannelService::rotateWebhookHash` queues
  its routing swap with `DB::afterCommit` ahead of the save, so the save's provider sync runs behind it.
  Enforced: `ChannelWebhookConsistencyTest`.
- **A provider refusal never fails a channel write; the register outcome is stored on the channel, a
  deregister is best-effort** (`ChannelObserver` catches and reports, `SyncChannelWebhookJob` stores
  `webhook_status`, `ChannelWebhookSyncOutcome` notes refusals for the request). Why: a refused write
  used to leave a saved but dead channel behind a server error, and abort cascades (deactivating or
  deleting an assistant) halfway. The error text is never stored; a rotation is not rolled back on a
  refusal, because a leaked hash that stays valid is worse than a channel waiting for re-registration.
  A driver's exception must already be redacted. Enforced: `ChannelWebhookConsistencyTest`,
  `SyncChannelWebhookJobTest`, `ChannelsConsoleTest`.
- **The webhook public hash is rotated only through `ChannelService`.**
- **Rotating the webhook hash needs `Permission::RotateChannelToken` and access to the channel's
  assistant; `ManageAssistants` is not enough** (`ChannelPolicy::rotateWebhook`, admins through
  `Gate::before`; `ChannelRotationAuthorizationTest`). Why: rotation drops the old hash from routing
  at once, so deliveries fail until the provider is switched to the new URL; it is a separately
  granted, sensitive permission — `content_manager` does not have it.
- **Viewing, creating, editing and deleting channels needs `Permission::ManageChannels` and access to
  the channel's assistant; `ManageAssistants` gives no channel access** (`ChannelPolicy`,
  `ChannelPolicyTest`). Why: a channel holds the messenger's credentials, so a role can build
  assistants and flows without seeing bot tokens. `content_manager` has both permissions.

## Rules

- **Send through `MessageSenderInterface`, never through `ProviderSenderInterface` or a provider
  namespace.** Why: idempotency, rate limit and transcript live in `MessageSender`. Enforced for
  Flow only, by `tests/Architecture/MessagingBoundariesTest.php`: a provider module is any
  sub-namespace of `App\Domains\Channels` except the shared layers (`Contracts`, `DTOs`, `Enums`,
  `Models`, `Observers`, `Policies`, `Providers`, `Services`), so a new provider is covered
  automatically and a new shared layer must be added to that exclusion list.
- **A new messenger is a new integration, not a branch.** Add a `ChannelIntegrationDefinition`
  tagged `channels.integration` with its adapter, sender and registrar, in its own provider —
  `Telegram/TelegramChannelServiceProvider.php` is the model. Review only. *(proposed)*
- **A channel secret never leaves the request or job that uses it.** A provider client throws
  exceptions whose text went through `SecretRedactor` and does not chain the original (a connection
  error's message carries the URL, and Telegram's URL carries the bot token); a queued job names the
  channel and loads its credentials when it runs. Only a synchronous deregister carries a snapshot,
  because a deleted row cannot be loaded. Why: exception text and job payloads end up in logs,
  `jobs` and `failed_jobs`. Enforced: `TelegramBotApiClientRedactionTest`,
  `SyncChannelWebhookJobCredentialsTest`, `BroadcastSendJobTest`.
- **Pick the queue by purpose:** replies in an active dialogue `messaging.transactional`,
  broadcasts `messaging.broadcast`, service and lifecycle work `messaging.system`. Source:
  `conventions/queues.md`.
